<?php

namespace EmrVoice\Tests\Support;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Uri;
use Ratchet\RFC6455\Handshake\ClientNegotiator;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;

/** Minimal WebSocket client for tests (behaves like resources/js/emr-voice-recorder.js). */
final class WsClient
{
    /** @var list<array<string, mixed>> */
    public array $messages = [];
    public ?int $httpStatus = null;
    public bool $open = false;
    public bool $closed = false;
    public ?int $closeCode = null;
    private ?ConnectionInterface $conn = null;
    private ?MessageBuffer $buffer = null;
    /** @var list<callable(array): void> */
    public array $onMessage = [];

    public function __construct(private readonly LoopInterface $loop, private readonly string $url)
    {
    }

    public function connect(): void
    {
        $uri = new Uri($this->url);
        $negotiator = new ClientNegotiator(new HttpFactory());
        $request = $negotiator->generateRequest($uri);
        (new Connector([], $this->loop))->connect($uri->getHost() . ':' . $uri->getPort())->then(function (ConnectionInterface $c) use ($request) {
            $this->conn = $c;
            $head = '';
            $c->on('data', function (string $data) use (&$head) {
                if ($this->buffer) {
                    $this->buffer->onData($data);
                    return;
                }
                $head .= $data;
                if (($end = strpos($head, "\r\n\r\n")) === false) {
                    return;
                }
                $response = Message::parseResponse(substr($head, 0, $end + 4));
                $this->httpStatus = $response->getStatusCode();
                if ($this->httpStatus !== 101) {
                    $this->conn->close();
                    return;
                }
                $this->open = true;
                $this->buffer = new MessageBuffer(new CloseFrameChecker(),
                    function (MessageInterface $m) {
                        $msg = json_decode($m->getPayload(), true);
                        $this->messages[] = $msg;
                        foreach ($this->onMessage as $fn) {
                            $fn($msg);
                        }
                    },
                    function (FrameInterface $f) {
                        if ($f->getOpcode() === Frame::OP_CLOSE) {
                            $this->closeCode = strlen($f->getPayload()) >= 2 ? unpack('n', substr($f->getPayload(), 0, 2))[1] : null;
                            $this->buffer->sendFrame(new Frame(pack('n', 1000), true, Frame::OP_CLOSE)); // as browsers do
                            $this->conn->end();
                        }
                    },
                    false, null, 0, 0, fn (string $d) => $this->conn->write($d));
                $rest = substr($head, $end + 4);
                if ($rest !== '') {
                    $this->buffer->onData($rest);
                }
            });
            $c->on('close', function () {
                $this->closed = true;
                $this->open = false;
            });
            $c->write(Message::toString($request));
        });
    }

    public function sendAudio(string $pcm): void
    {
        $this->buffer?->sendMessage($pcm, true, true);
    }

    public function sendJson(array $msg): void
    {
        $this->buffer?->sendMessage(json_encode($msg));
    }

    /** Drop the TCP connection without a close handshake (network loss). */
    public function kill(): void
    {
        $this->conn?->close();
    }

    /** @return list<array<string, mixed>> */
    public function ofType(string $type): array
    {
        return array_values(array_filter($this->messages, fn ($m) => ($m['type'] ?? null) === $type));
    }
}
