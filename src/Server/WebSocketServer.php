<?php

namespace EmrVoice\Server;

use EmrVoice\Auth\TokenSigner;
use EmrVoice\Exceptions\InvalidToken;
use EmrVoice\Recording\Recording;
use EmrVoice\Recording\RecordingFactory;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

/**
 * WebSocket endpoint for live recordings: wss://host{path}?token=…
 *
 *   client → server  binary frames: PCM16LE, 16 kHz, mono (any size, in order)
 *                    {"type":"stop"} | {"type":"cancel"} | {"type":"ping"}
 *   server → client  {"type":"ready","resumeOffset":n} — send audio from byte n of the stream
 *                    {"type":"ack","offset":n} — bytes before n are safe, the client may drop them
 *                    {"type":"chunk",…} {"type":"silence",…} {"type":"finishing",…}
 *                    {"type":"done",…} {"type":"error","code":…,"message":…} {"type":"pong"}
 *
 * A dropped connection keeps the recording in memory for server.resume_grace_s; reconnecting with the same
 * recording resumes it. After the grace period the recording is finished (or cancelled, per on_disconnect).
 */
final class WebSocketServer
{
    private const MAX_HANDSHAKE_BYTES = 16384;

    private ?SocketServer $socket = null;
    private ServerNegotiator $negotiator;
    /** @var array<string, Recording> */
    private array $recordings = [];
    /** @var array<string, TimerInterface> */
    private array $graceTimers = [];
    /** @var \SplObjectStorage<WsPeer, null> */
    private \SplObjectStorage $peers;
    private bool $stopping = false;

    /** @param array<string, mixed> $config emr-voice.server */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly array $config,
        private readonly TokenSigner $tokens,
        private readonly RecordingFactory $factory,
        private readonly LoggerInterface $log,
    ) {
        $this->negotiator = new ServerNegotiator(new RequestVerifier(), new HttpFactory());
        $this->negotiator->setStrictSubProtocolCheck(false);
        $this->peers = new \SplObjectStorage();
    }

    public function listen(): string
    {
        $uri = "{$this->config['host']}:{$this->config['port']}";
        $this->socket = new SocketServer($uri, [], $this->loop);
        $this->socket->on('connection', fn (ConnectionInterface $c) => $this->onConnection($c));
        $this->loop->addPeriodicTimer(5, fn () => $this->checkIdle());
        $this->log->info("emr-voice: listening on ws://{$uri}{$this->config['path']}");
        return $uri;
    }

    public function address(): ?string
    {
        return $this->socket?->getAddress();
    }

    /** Graceful stop: refuse new connections and tell browsers to reconnect (they resume from their last ack). */
    public function stop(): void
    {
        $this->stopping = true;
        $this->socket?->close();
        foreach (array_keys($this->graceTimers) as $id) {
            $this->cancelGrace($id);
        }
        foreach ($this->peers as $peer) {
            $peer->close(1012, 'server restarting');
        }
    }

    /** @return array<string, Recording> */
    public function recordings(): array
    {
        return $this->recordings;
    }

    private function onConnection(ConnectionInterface $socket): void
    {
        if ($this->stopping || count($this->peers) >= $this->config['max_connections']) {
            $socket->end(Message::toString(new Response(503, ['Connection' => 'close'], 'busy')));
            return;
        }
        $peer = new WsPeer($socket, (string) $socket->getRemoteAddress(), $this->loop);
        $this->peers->attach($peer);
        $head = '';

        $socket->on('data', $onData = function (string $data) use (&$head, &$onData, $peer, $socket) {
            if ($peer->buffer) {
                $peer->buffer->onData($data);
                return;
            }
            $head .= $data;
            if (strlen($head) > self::MAX_HANDSHAKE_BYTES) {
                $socket->end(Message::toString(new Response(431)));
                return;
            }
            $end = strpos($head, "\r\n\r\n");
            if ($end === false) {
                return;
            }
            $rest = substr($head, $end + 4);
            $this->handshake($peer, substr($head, 0, $end + 4));
            if ($peer->buffer && $rest !== '') {
                $peer->buffer->onData($rest);
            }
        });
        $socket->on('close', fn () => $this->onClose($peer));
        $socket->on('error', fn (\Throwable $e) => $this->log->warning('emr-voice: socket error', ['error' => $e->getMessage()]));
    }

    private function handshake(WsPeer $peer, string $raw): void
    {
        try {
            $request = Message::parseRequest($raw);
        } catch (\Throwable) {
            $this->reject($peer, 400, 'Bad request');
            return;
        }
        if (rtrim($request->getUri()->getPath(), '/') !== rtrim($this->config['path'], '/')) {
            $this->reject($peer, 404, 'Not found');
            return;
        }
        $origins = $this->config['allowed_origins'] ?? [];
        if ($origins !== [] && !in_array($request->getHeaderLine('Origin'), $origins, true)) {
            $this->reject($peer, 403, 'Origin not allowed');
            return;
        }
        parse_str($request->getUri()->getQuery(), $query);
        try {
            $claims = $this->tokens->verify((string) ($query['token'] ?? ''));
        } catch (InvalidToken $e) {
            $this->reject($peer, 401, $e->getMessage());
            return;
        }

        $response = $this->negotiator->handshake($request);
        if ($response->getStatusCode() !== 101) {
            $peer->socket->end(Message::toString($response));
            return;
        }
        $peer->socket->write(Message::toString($response));

        $recordingId = (string) $claims['rid'];
        $peer->buffer = new MessageBuffer(
            new CloseFrameChecker(),
            fn (MessageInterface $m) => $this->onMessage($peer, $recordingId, $m),
            fn (FrameInterface $f) => $this->onControl($peer, $f),
            true,
            null,
            $this->config['max_frame_bytes'],
            $this->config['max_frame_bytes'],
            fn (string $data) => $peer->socket->write($data),
        );
        $this->open($peer, $recordingId, $claims);
    }

    private function reject(WsPeer $peer, int $status, string $message): void
    {
        $this->log->notice('emr-voice: connection rejected', ['status' => $status, 'reason' => $message, 'remote' => $peer->remote]);
        $peer->socket->end(Message::toString(new Response($status, ['Content-Type' => 'text/plain', 'Connection' => 'close'], $message)));
    }

    /** @param array<string, mixed> $claims */
    private function open(WsPeer $peer, string $recordingId, array $claims): void
    {
        if (isset($this->recordings[$recordingId])) {
            $this->resume($peer, $this->recordings[$recordingId]);
            return;
        }
        $this->factory->open($recordingId, $claims)->then(
            function (?Recording $rec) use ($peer, $recordingId) {
                if ($peer->isClosed()) {
                    if ($rec && !$rec->isDone() && !isset($this->recordings[$recordingId])) {
                        $this->track($rec);
                        $this->startGrace($rec);
                    }
                    return;
                }
                if ($rec === null) {
                    $peer->send(['type' => 'done', 'alreadyFinished' => true]);
                    $peer->close(1000, 'already finished');
                    return;
                }
                if (isset($this->recordings[$recordingId])) {
                    $rec = $this->recordings[$recordingId]; // a parallel connection won the race
                } else {
                    $this->track($rec);
                }
                $this->resume($peer, $rec);
            },
            function (\Throwable $e) use ($peer, $recordingId) {
                $this->log->error('emr-voice: could not open recording', ['recordingId' => $recordingId, 'error' => $e->getMessage()]);
                $peer->send(['type' => 'error', 'code' => 'open_failed', 'message' => 'Could not start the recording session, retry shortly']);
                $peer->close(1011, 'open failed');
            }
        );
    }

    private function track(Recording $rec): void
    {
        $this->recordings[$rec->recordingId] = $rec;
        $rec->onDone = function (Recording $r) {
            $this->cancelGrace($r->recordingId);
            unset($this->recordings[$r->recordingId]);
        };
    }

    private function resume(WsPeer $peer, Recording $rec): void
    {
        $this->cancelGrace($rec->recordingId);
        $peer->recordingId = $rec->recordingId;
        $rec->attach($peer);
    }

    private function onMessage(WsPeer $peer, string $recordingId, MessageInterface $m): void
    {
        $rec = $this->recordings[$recordingId] ?? null;
        if ($m->isBinary()) {
            if ($rec === null || $peer->recordingId !== $recordingId) {
                $peer->send(['type' => 'error', 'code' => 'not_ready', 'message' => 'Wait for {"type":"ready"} before sending audio']);
                return;
            }
            $rec->append($m->getPayload());
            return;
        }
        $msg = json_decode($m->getPayload(), true);
        match ($msg['type'] ?? null) {
            'stop' => $rec?->finish(),
            'cancel' => $rec?->cancel(),
            'ping' => $peer->send(['type' => 'pong']),
            default => $peer->send(['type' => 'error', 'code' => 'bad_message', 'message' => 'Unknown message']),
        };
    }

    private function onControl(WsPeer $peer, FrameInterface $frame): void
    {
        match ($frame->getOpcode()) {
            Frame::OP_PING => $peer->buffer?->sendFrame(new Frame($frame->getPayload(), true, Frame::OP_PONG)),
            Frame::OP_CLOSE => $peer->onCloseFrame(),
            default => null,
        };
    }

    private function onClose(WsPeer $peer): void
    {
        $peer->markClosed();
        $this->peers->detach($peer);
        $rec = $peer->recordingId ? ($this->recordings[$peer->recordingId] ?? null) : null;
        if ($rec === null) {
            return;
        }
        $rec->detach($peer);
        // While stopping, browsers reconnect to the restarted process, which resumes from the store.
        if (!$this->stopping && !$rec->isConnected() && !$rec->isDone()) {
            $this->startGrace($rec);
        }
    }

    private function startGrace(Recording $rec): void
    {
        $this->cancelGrace($rec->recordingId);
        $this->graceTimers[$rec->recordingId] = $this->loop->addTimer($this->config['resume_grace_s'], function () use ($rec) {
            unset($this->graceTimers[$rec->recordingId]);
            if ($rec->isConnected() || $rec->isDone()) {
                return;
            }
            $this->log->info('emr-voice: no reconnect within grace period', ['recordingId' => $rec->recordingId, 'action' => $this->config['on_disconnect']]);
            $this->config['on_disconnect'] === 'cancel' ? $rec->cancel() : $rec->finish(auto: true);
        });
    }

    private function cancelGrace(string $recordingId): void
    {
        if (isset($this->graceTimers[$recordingId])) {
            $this->loop->cancelTimer($this->graceTimers[$recordingId]);
            unset($this->graceTimers[$recordingId]);
        }
    }

    private function checkIdle(): void
    {
        $limit = microtime(true) - $this->config['idle_timeout_s'];
        foreach ($this->peers as $peer) {
            $rec = $peer->recordingId ? ($this->recordings[$peer->recordingId] ?? null) : null;
            if ($rec && !$rec->isFinishing() && $rec->lastAudioAt < $limit) {
                $peer->send(['type' => 'error', 'code' => 'idle', 'message' => 'No audio received']);
                $peer->close(4001, 'idle');
            }
        }
    }
}
