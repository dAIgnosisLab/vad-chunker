<?php

namespace EmrVoice\Events;

/** No EMR was produced (status: no_speech, cancelled, failed or timeout). */
final class EmrFailed
{
    public function __construct(
        public readonly string $recordingId,
        public readonly string $sessionId,
        public readonly string $status
    ) {
    }
}
