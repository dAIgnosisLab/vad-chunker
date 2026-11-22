<?php

namespace VadChunker\Vad;

use VadChunker\Audio\Pcm;

/**
 * Port of planChunks() and helpers from EMR_websocket/src/audio/vad.js. Arithmetic is kept in the same
 * order as the JS so cut points match it frame for frame (see tests/ParityTest.php).
 */
final class Planner
{
    public const SR = Pcm::SAMPLE_RATE;
    public const FRAME = SileroModel::FRAME;
    public const FRAME_S = self::FRAME / self::SR;

    /** JS Math.round for the non-negative values used here (PHP round() pre-rounds on PHP < 8.4). */
    public static function jsRound(float $x): float
    {
        return floor($x + 0.5);
    }

    public static function round(float $x, int $d): float
    {
        $f = 10 ** $d;
        return self::jsRound($x * $f) / $f;
    }

    /** @param list<float> $probs @return list<int> */
    public static function speechFlags(array $probs, VadConfig $cfg): array
    {
        $flags = [];
        $on = false;
        foreach ($probs as $p) {
            $on = !$on ? $p >= $cfg->threshold : $p >= $cfg->negThreshold;
            $flags[] = $on ? 1 : 0;
        }
        return $flags;
    }

    /** @param list<int> $flags @return list<array{0: float, 1: float}> [start_s, end_s] of every run of non-speech frames */
    public static function pauses(array $flags): array
    {
        $out = [];
        $n = count($flags);
        $k = 0;
        while ($k < $n) {
            if ($flags[$k]) {
                $k++;
                continue;
            }
            $j = $k;
            while ($j < $n && !$flags[$j]) {
                $j++;
            }
            $out[] = [$k * self::FRAME_S, $j * self::FRAME_S];
            $k = $j;
        }
        return $out;
    }

    /** @param list<int> $flags */
    public static function longestRun(array $flags, int $a, int $b): int
    {
        $best = 0;
        $cur = 0;
        for ($k = $a; $k < $b; $k++) {
            $cur = $flags[$k] ? $cur + 1 : 0;
            if ($cur > $best) {
                $best = $cur;
            }
        }
        return $best;
    }

    public static function quietestPoint(string $pcm, float $a, float $b, float $winS = 0.3, float $hopS = 0.05): float
    {
        $lo = (int) floor($a * self::SR);
        $hi = (int) floor($b * self::SR);
        $win = (int) floor($winS * self::SR);
        $hop = (int) floor($hopS * self::SR);
        $len = min($hi, Pcm::samples($pcm)) - $lo;
        if ($len <= $win) {
            return ($a + $b) / 2;
        }
        $wav = Pcm::toFloats(substr($pcm, $lo * 2, $len * 2));
        $bestI = 0;
        $bestE = INF;
        $idx = 0;
        for ($i = 0; $i < $len - $win; $i += $hop, $idx++) {
            $e = 0.0;
            for ($s = $i, $end = $i + $win; $s < $end; $s++) {
                $e += $wav[$s] * $wav[$s];
            }
            $e /= $win;
            if ($e < $bestE) {
                $bestE = $e;
                $bestI = $idx;
            }
        }
        return ($lo + $bestI * $hop + intdiv($win, 2)) / self::SR;
    }

    /**
     * Plan chunks over a buffer (times relative to the buffer start).
     *
     * @param list<float> $probs
     * @param int|null $limit stop after this many chunks (the live chunker only needs the first)
     * @param bool $resolveQuietest when false, a quietest-point cut is returned with end = null instead of
     *                              being searched for (the live chunker discards it until it is ready)
     * @return list<array{index: int, start: float, end: ?float, reason: string, speechRatio: float, longestSpeechS: float}>
     */
    public static function plan(string $pcm, array $probs, VadConfig $cfg, ?int $limit = null, bool $resolveQuietest = true): array
    {
        $total = Pcm::samples($pcm) / self::SR;
        $flags = self::speechFlags($probs, $cfg);
        $ps = self::pauses($flags);
        $nFlags = count($flags);
        $chunks = [];
        $start = 0.0;

        while ($total - $start > 0.05) {
            $cut = null;
            $reason = null;
            foreach ($ps as [$pStart, $pEnd]) {
                if ($pEnd <= $start) {
                    continue;
                }
                $s = max($pStart, $start);
                $dur = $pEnd - $s + 1e-6;
                if ($dur < $cfg->minPauseS) {
                    continue;
                }
                $c = $s + min($dur / 2, 1.0);
                if ($c < $start + $cfg->minS) {
                    $tgt = $start + $cfg->targetS;
                    $c = $pEnd - $tgt >= 0.15 ? $tgt : $start + $cfg->minS;
                    if ($pEnd - $c < 0.15) {
                        continue;
                    }
                }
                $elapsed = $c - $start;
                if ($elapsed > $cfg->maxS || $c >= $total) {
                    break;
                }
                if ($elapsed < $cfg->minS) {
                    continue;
                }
                if ($elapsed >= $cfg->targetS) {
                    $cut = $c;
                    $reason = 'pause';
                    break;
                }
                if ($dur >= $cfg->longPauseS) {
                    $cut = $c;
                    $reason = 'long-pause';
                    break;
                }
            }
            if ($cut === null) {
                if ($total - $start <= $cfg->maxS) {
                    $cut = $total;
                    $reason = 'end';
                } else {
                    $reason = 'quietest-point';
                    if (!$resolveQuietest) {
                        $chunks[] = ['index' => count($chunks), 'start' => self::round($start, 3), 'end' => null,
                            'reason' => $reason, 'speechRatio' => 0.0, 'longestSpeechS' => 0.0];
                        return $chunks;
                    }
                    $cut = self::quietestPoint($pcm, $start + $cfg->minS, $start + $cfg->maxS);
                }
            }

            $a = (int) floor($start / self::FRAME_S);
            $b = max($a + 1, (int) floor($cut / self::FRAME_S));
            $sum = 0;
            for ($k = $a, $m = min($b, $nFlags); $k < $m; $k++) {
                $sum += $flags[$k];
            }
            $chunks[] = [
                'index' => count($chunks),
                'start' => self::round($start, 3),
                'end' => self::round($cut, 3),
                'reason' => $reason,
                'speechRatio' => $b > $a ? self::round($sum / ($b - $a), 3) : 0.0,
                'longestSpeechS' => self::round(self::longestRun($flags, $a, min($b, $nFlags)) * self::FRAME_S, 2),
            ];
            if ($limit !== null && count($chunks) >= $limit) {
                break;
            }
            $start = $cut;
        }
        return $chunks;
    }
}
