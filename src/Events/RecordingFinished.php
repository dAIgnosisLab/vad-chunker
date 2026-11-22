<?php

namespace EmrVoice\Events;

/** The final chunk was uploaded; the EMR is being generated. */
final class RecordingFinished
{
    public function __construct(
        public readonly string $recordingId,
        public readonly string $sessionId,
        public readonly int $chunks,
        public readonly bool $auto
    ) {
    }
}
