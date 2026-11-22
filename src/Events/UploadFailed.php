<?php

namespace EmrVoice\Events;

/** A chunk upload keeps failing (still being retried unless permanent). */
final class UploadFailed
{
    public function __construct(
        public readonly string $recordingId,
        public readonly string $sessionId,
        public readonly int $chunkIndex,
        public readonly int $attempts,
        public readonly string $error,
        public readonly bool $permanent
    ) {
    }
}
