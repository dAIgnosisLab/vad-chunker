<?php

namespace EmrVoice\Recording;

/** The browser end of a recording (a WebSocket connection). */
interface Peer
{
    /** @param array<string, mixed> $message sent as a JSON text frame */
    public function send(array $message): void;

    public function close(int $code = 1000, string $reason = ''): void;
}
