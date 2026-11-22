<?php

namespace EmrVoice\Events;

/** Our EMR session was created for a new recording. */
final class RecordingStarted
{
    public function __construct(
        public readonly string $recordingId,
        public readonly string $sessionId,
        public readonly string $patientId,
        public readonly string $doctorId,
        public readonly string $hospitalId
    ) {
    }
}
