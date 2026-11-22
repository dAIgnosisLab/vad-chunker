<?php

namespace EmrVoice\Vad;

use EmrVoice\Audio\Pcm;

/**
 * Port of LiveChunker (EMR_websocket/src/audio/vad.js): Planner::plan applied to a live 16 kHz stream.
 * Only the current (not yet cut) chunk is kept, as Int16 bytes. Times are seconds since the stream began.
 *
 * A cut is final once settleS of later audio follows it (quietest-point cuts: at maxS + settleS).
 * Silence before any speech is dropped (keeping keepS before the next word) and reported via takeSilences();
 * long leading/trailing silence inside a cut chunk is trimmed from the audio handed out.
 */
final class LiveChunker
{
    private const SR = Planner::SR;
    private const FRAME = Planner::FRAME;
    private const FRAME_S = Planner::FRAME_S;

    private StreamingVad $vad;
    private string $buf = '';
    /** @var list<float> */
    private array $probs = [];
    /** samples before the buffer start (handed out as chunks or dropped as silence) */
    public int $offset = 0;
    public int $count = 0;
    public float $lastSpeechS = 0.0;
    public bool $everSpoke = false;
    public float $droppedS = 0.0;
    private int $run = 0;
    private ?float $silenceStart = null;
    /** @var list<array{start: float, end: float, durationS: float}> */
    private array $silences = [];

    public function __construct(SileroModel $model, private readonly VadConfig $cfg = new VadConfig(), int $startOffset = 0)
    {
        $this->vad = new StreamingVad($model);
        $this->offset = $startOffset;
    }

    public function elapsedS(): float
    {
        return ($this->offset + Pcm::samples($this->buf)) / self::SR;
    }

    public function chunkS(): float
    {
        return Pcm::samples($this->buf) / self::SR;
    }

    /** @return list<array{start: float, end: float, durationS: float}> silent spans reported since the last call */
    public function takeSilences(): array
    {
        $s = $this->silences;
        $this->silences = [];
        return $s;
    }

    /**
     * Add audio (Int16LE, 16 kHz mono). Returns chunks that became final.
     *
     * @return list<array{chunk: array<string, mixed>, audio: string}>
     */
    public function feed(string $pcm): array
    {
        if ($pcm === '') {
            return [];
        }
        $before = count($this->probs);
        $this->buf .= $pcm;
        array_push($this->probs, ...$this->vad->feed($pcm));

        $runNeeded = max(1, (int) Planner::jsRound($this->cfg->minSpeechRunS / self::FRAME_S));
        for ($k = $before, $n = count($this->probs); $k < $n; $k++) {
            $this->run = $this->probs[$k] >= $this->cfg->threshold ? $this->run + 1 : 0;
            if ($this->run >= $runNeeded) {
                $this->lastSpeechS = $this->offset / self::SR + ($k + 1) * self::FRAME_S;
                $this->everSpoke = true;
            }
        }
        if ($this->cfg->dropSilence) {
            $this->dropSilence();
        }

        $out = [];
        while ($this->chunkS() >= $this->cfg->minS) {
            $n = $this->chunkS();
            $quietestReady = $n >= $this->cfg->maxS + $this->cfg->settleS;
            $first = Planner::plan($this->buf, $this->probs, $this->cfg, 1, $quietestReady)[0];
            $ready = (($first['reason'] === 'pause' || $first['reason'] === 'long-pause') && $first['end'] <= $n - $this->cfg->settleS)
                || ($first['reason'] === 'quietest-point' && $quietestReady);
            if (!$ready) {
                break;
            }
            $out[] = $this->cut($first);
            if ($this->cfg->dropSilence) {
                $this->dropSilence();
            }
        }
        return $out;
    }

    /**
     * Recording stopped: whatever is left becomes the last chunk(s); a speech-free remainder is dropped.
     *
     * @return list<array{chunk: array<string, mixed>, audio: string}>
     */
    public function finish(): array
    {
        $restSamples = Pcm::samples($this->vad->rest);
        if ($restSamples > 0) {
            $pad = (self::FRAME - ($restSamples % self::FRAME)) % self::FRAME;
            array_push($this->probs, ...$this->vad->feed(str_repeat("\0", $pad * 2)));
        }
        $out = [];
        if ($this->cfg->dropSilence && !$this->hasSpeech()) {
            if ($this->silenceStart === null && $this->chunkS() > 0) {
                $this->silenceStart = $this->offset / self::SR;
            }
            $samples = Pcm::samples($this->buf);
            $this->offset += $samples;
            $this->droppedS += $samples / self::SR;
            $this->buf = '';
            $this->probs = [];
            $this->closeSilence($this->offset / self::SR);
            return $out;
        }
        $this->closeSilence($this->offset / self::SR);
        while ($this->chunkS() > 0.05) {
            $out[] = $this->cut(Planner::plan($this->buf, $this->probs, $this->cfg, 1)[0]);
        }
        return $out;
    }

    private function hasSpeech(): bool
    {
        foreach ($this->probs as $p) {
            if ($p >= $this->cfg->threshold) {
                return true;
            }
        }
        return false;
    }

    /** The open chunk has no speech yet: keep only its last keepS seconds. */
    private function dropSilence(): void
    {
        if ($this->hasSpeech()) {
            $this->closeSilence($this->offset / self::SR);
            return;
        }
        $keep = (int) Planner::jsRound($this->cfg->keepS / self::FRAME_S);
        $drop = count($this->probs) - $keep;
        if ($drop <= 0) {
            return;
        }
        if ($this->silenceStart === null) {
            $this->silenceStart = $this->offset / self::SR;
        }
        $n = $drop * self::FRAME;
        $this->buf = substr($this->buf, $n * 2);
        $this->probs = array_slice($this->probs, $drop);
        $this->offset += $n;
        $this->droppedS += $n / self::SR;
    }

    private function closeSilence(float $end): void
    {
        if ($this->silenceStart === null) {
            return;
        }
        $d = $end - $this->silenceStart;
        if ($d >= $this->cfg->silenceReportS) {
            $this->silences[] = [
                'start' => Planner::round($this->silenceStart, 2),
                'end' => Planner::round($end, 2),
                'durationS' => Planner::round($d, 1),
            ];
        }
        $this->silenceStart = null;
    }

    /** @param array<string, mixed> $ch @return array{startSample: int, chunk: array<string, mixed>, audio: string} */
    private function cut(array $ch): array
    {
        $bufSamples = Pcm::samples($this->buf);
        if ($ch['reason'] === 'end') {
            $n = $bufSamples;
            $frames = count($this->probs);
        } else {
            $frames = max(1, (int) Planner::jsRound($ch['end'] / self::FRAME_S));
            $n = min($bufSamples, $frames * self::FRAME);
        }

        // Trim long leading / trailing silence from the audio handed out (times stay the real stream times).
        $a = 0;
        $b = $n;
        $flags = Planner::speechFlags(array_slice($this->probs, 0, min($frames, count($this->probs))), $this->cfg);
        $fs = -1;
        $ls = -1;
        foreach ($flags as $k => $f) {
            if ($f) {
                if ($fs < 0) {
                    $fs = $k;
                }
                $ls = $k;
            }
        }
        $pad = (int) Planner::jsRound($this->cfg->trimPadS / self::FRAME_S);
        if ($this->cfg->trim && $fs >= 0) {
            if ($fs * self::FRAME_S > $this->cfg->trimMinS) {
                $a = max(0, $fs - $pad) * self::FRAME;
            }
            $tailS = $n / self::SR - ($ls + 1) * self::FRAME_S;
            if ($tailS > $this->cfg->trimMinS) {
                $b = min($n, ($ls + 1 + $pad) * self::FRAME);
            }
        }

        $audio = substr($this->buf, $a * 2, ($b - $a) * 2);
        $this->buf = substr($this->buf, $n * 2);
        $this->probs = array_slice($this->probs, $frames);
        $startSample = $this->offset;
        $start = $this->offset / self::SR;
        $this->offset += $n;

        return [
            'startSample' => $startSample,
            'chunk' => [
                'index' => $this->count++,
                'start' => Planner::round($start, 3),
                'end' => Planner::round($this->offset / self::SR, 3),
                'reason' => $ch['reason'],
                'speechRatio' => $ch['speechRatio'],
                'longestSpeechS' => $ch['longestSpeechS'],
                'speechStart' => Planner::round($start + $a / self::SR, 3),
                'speechEnd' => Planner::round($start + $b / self::SR, 3),
                'trimmedS' => Planner::round(($n - ($b - $a)) / self::SR, 2),
            ],
            'audio' => $audio,
        ];
    }
}
