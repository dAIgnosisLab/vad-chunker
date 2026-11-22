<?php

namespace EmrVoice\Server;

use EmrVoice\Recording\Peer;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;

final class WsPeer implements Peer
{
    private const CLOSE_WAIT_S = 2.0;

    public ?MessageBuffer $buffer = null;
    public ?string $recordingId = null;
    private bool $closing = false;
    private bool $closed = false;

    public function __construct(public readonly ConnectionInterface $socket, public readonly string $remote, private readonly LoopInterface $loop)
    {
    }

    public function send(array $message): void
    {
        if (!$this->closing && !$this->closed && $this->buffer) {
            $this->buffer->sendMessage(json_encode($message, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        }
    }

    /**
     * Start the close handshake. The socket stays open (and keeps being read) until the client answers with
     * its close frame: closing a socket with unread incoming audio makes the kernel reset the connection,
     * and the client can lose the last messages (e.g. "done").
     */
    public function close(int $code = 1000, string $reason = ''): void
    {
        if ($this->closing || $this->closed) {
            return;
        }
        $this->closing = true;
        if (!$this->buffer) {
            $this->socket->end();
            return;
        }
        $this->buffer->sendFrame(new Frame(pack('n', $code) . substr($reason, 0, 120), true, Frame::OP_CLOSE));
        $this->loop->addTimer(self::CLOSE_WAIT_S, fn () => $this->closed ? null : $this->socket->close());
    }

    /** The client sent a close frame: answer it (unless we started) and end the connection. */
    public function onCloseFrame(): void
    {
        if (!$this->closing && $this->buffer) {
            $this->closing = true;
            $this->buffer->sendFrame(new Frame(pack('n', 1000), true, Frame::OP_CLOSE));
        }
        $this->socket->end();
    }

    public function isClosed(): bool
    {
        return $this->closing || $this->closed;
    }

    public function markClosed(): void
    {
        $this->closed = true;
    }
}
