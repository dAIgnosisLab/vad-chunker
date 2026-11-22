<?php

namespace EmrVoice\Events;

/** A silent span of at least vad.silence_report_s seconds (stream seconds). */
final class SilenceDetected
{
    public function __construct(
        public readonly string $recordingId,
        public readonly float $start,
        public readonly float $end,
        public readonly float $durationS
    ) {
    }
}
