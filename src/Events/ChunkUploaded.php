<?php

namespace EmrVoice\Events;

/** A pause-cut chunk reached our pipeline. Fired inside the WebSocket process: make listeners ShouldQueue if they do real work. */
final class ChunkUploaded
{
    public function __construct(
        public readonly string $recordingId,
        public readonly string $sessionId,
        public readonly int $chunkIndex,
        public readonly bool $isFinal,
        public readonly array $vad
    ) {
    }
}
