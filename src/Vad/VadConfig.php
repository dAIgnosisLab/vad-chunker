<?php

namespace VadChunker\Vad;

/** Chunking rules. Defaults are the tested values from vad.js / vad_chunker.py — change only together with the pipeline. */
final class VadConfig
{
    public function __construct(
        public readonly float $minS = 45,
        public readonly float $targetS = 60,
        public readonly float $maxS = 90,
        public readonly float $minPauseS = 0.3,
        public readonly float $longPauseS = 0.8,
        public readonly float $threshold = 0.5,
        public readonly float $negThreshold = 0.35,
        public readonly float $settleS = 2.0,
        public readonly bool $dropSilence = true,
        public readonly float $keepS = 1.0,
        public readonly float $silenceReportS = 5,
        public readonly bool $trim = true,
        public readonly float $trimMinS = 1.0,
        public readonly float $trimPadS = 0.3,
        public readonly float $minSpeechRunS = 0.25,
    ) {
    }

    /** @param array<string, mixed> $c snake_case keys as in config/vad-chunker.php */
    public static function fromArray(array $c): self
    {
        $map = [
            'min_s' => 'minS', 'target_s' => 'targetS', 'max_s' => 'maxS',
            'min_pause_s' => 'minPauseS', 'long_pause_s' => 'longPauseS',
            'threshold' => 'threshold', 'neg_threshold' => 'negThreshold',
            'settle_s' => 'settleS', 'drop_silence' => 'dropSilence', 'keep_s' => 'keepS',
            'silence_report_s' => 'silenceReportS', 'trim' => 'trim',
            'trim_min_s' => 'trimMinS', 'trim_pad_s' => 'trimPadS', 'min_speech_run_s' => 'minSpeechRunS',
        ];
        $args = [];
        foreach ($map as $key => $prop) {
            if (array_key_exists($key, $c)) {
                $args[$prop] = $c[$key];
            }
        }
        return new self(...$args);
    }
}
