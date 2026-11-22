<?php

namespace EmrVoice\Events;

/** The EMR for a finished recording is available. */
final class EmrReady
{
    public function __construct(
        public readonly string $recordingId,
        public readonly string $sessionId,
        public readonly mixed $emr
    ) {
    }
}
