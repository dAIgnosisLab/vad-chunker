<?php

namespace VadChunker\Tests\Unit;

use VadChunker\Audio\Pcm;
use VadChunker\Exceptions\InvalidAudioFormat;
use VadChunker\Vad\Planner;
use VadChunker\Vad\VadConfig;
use PHPUnit\Framework\TestCase;

final class PcmTest extends TestCase
{
    public function test_wav_round_trip_is_exact(): void
    {
        $pcm = pack('v*', 0, 1, 65535, 32768, 32767, 12345);
        $wav = Pcm::toWav($pcm);
        $this->assertSame(44 + strlen($pcm), strlen($wav));
        $this->assertSame($pcm, Pcm::fromWav($wav));
        $this->assertSame([0.0, 1 / 32768, -1 / 32768, -1.0, 32767 / 32768, 12345 / 32768], Pcm::toFloats($pcm));
    }

    public function test_rejects_wrong_formats(): void
    {
        $stereo44k = 'RIFF' . pack('V', 36) . 'WAVE' . 'fmt ' . pack('VvvVVvv', 16, 1, 2, 44100, 176400, 4, 16) . 'data' . pack('V', 0);
        foreach (['not a wav', $stereo44k] as $bad) {
            try {
                Pcm::fromWav($bad);
                $this->fail('accepted a non 16 kHz mono PCM16 WAV');
            } catch (InvalidAudioFormat) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_js_rounding(): void
    {
        $this->assertSame(3.0, Planner::jsRound(2.5));
        $this->assertSame(2.0, Planner::jsRound(2.4999));
        $this->assertSame(1.005, Planner::round(1.0049999999999999, 3), 'same as JS Math.round(x * 1000) / 1000');
        $this->assertSame(8, (int) Planner::jsRound(0.25 / Planner::FRAME_S));
    }

    public function test_config_from_snake_case(): void
    {
        $c = VadConfig::fromArray(['min_s' => 30, 'drop_silence' => false, 'unknown' => 1]);
        $this->assertSame(30.0, $c->minS);
        $this->assertFalse($c->dropSilence);
        $this->assertSame(90.0, $c->maxS);
    }
}
