<?php

namespace EmrVoice\Audio;

use EmrVoice\Exceptions\InvalidAudioFormat;

/** 16 kHz mono PCM16LE helpers. Audio is kept as raw Int16 byte strings (2 bytes/sample) to bound memory. */
final class Pcm
{
    public const SAMPLE_RATE = 16000;
    public const BYTES_PER_SAMPLE = 2;

    public static function samples(string $pcm): int
    {
        return intdiv(strlen($pcm), self::BYTES_PER_SAMPLE);
    }

    public static function seconds(string $pcm): float
    {
        return self::samples($pcm) / self::SAMPLE_RATE;
    }

    /** @return list<float> samples scaled to [-1, 1) exactly as Int16 / 32768 */
    public static function toFloats(string $pcm): array
    {
        if ($pcm === '') {
            return [];
        }
        $out = [];
        foreach (unpack('s*', $pcm) as $v) {
            $out[] = $v / 32768.0;
        }
        return $out;
    }

    public static function toWav(string $pcm): string
    {
        $len = strlen($pcm);
        return 'RIFF' . pack('V', 36 + $len) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, 1, self::SAMPLE_RATE, self::SAMPLE_RATE * 2, 2, 16)
            . 'data' . pack('V', $len) . $pcm;
    }

    /** Validate a 16 kHz mono PCM16 WAV and return its sample bytes. */
    public static function fromWav(string $wav): string
    {
        if (strlen($wav) < 12 || substr($wav, 0, 4) !== 'RIFF' || substr($wav, 8, 4) !== 'WAVE') {
            throw new InvalidAudioFormat('Not a RIFF/WAVE file');
        }
        $pos = 12;
        $fmt = null;
        $total = strlen($wav);
        while ($pos + 8 <= $total) {
            $id = substr($wav, $pos, 4);
            $size = unpack('V', substr($wav, $pos + 4, 4))[1];
            $body = $pos + 8;
            if ($id === 'fmt ') {
                $fmt = unpack('vformat/vchannels/Vrate/Vbyterate/vblock/vbits', substr($wav, $body, 16));
            } elseif ($id === 'data') {
                if ($fmt === null) {
                    throw new InvalidAudioFormat('WAV data chunk before fmt chunk');
                }
                if ($fmt['format'] !== 1 || $fmt['channels'] !== 1 || $fmt['rate'] !== self::SAMPLE_RATE || $fmt['bits'] !== 16) {
                    throw new InvalidAudioFormat(sprintf(
                        'WAV must be PCM 16 kHz mono 16-bit, got format=%d channels=%d rate=%d bits=%d',
                        $fmt['format'], $fmt['channels'], $fmt['rate'], $fmt['bits']
                    ));
                }
                return substr($wav, $body, min($size, $total - $body));
            }
            $pos = $body + $size + ($size % 2);
        }
        throw new InvalidAudioFormat('WAV has no data chunk');
    }
}
