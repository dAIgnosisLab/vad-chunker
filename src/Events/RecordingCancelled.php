<?php

namespace EmrVoice\Events;

/** The recording was discarded; no EMR will be generated. */
final class RecordingCancelled
{
    public function __construct(
        public readonly string $recordingId,
        public readonly string $sessionId
    ) {
    }
}
