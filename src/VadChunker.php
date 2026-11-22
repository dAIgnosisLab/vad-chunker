<?php

namespace VadChunker;

use VadChunker\Audio\Pcm;
use VadChunker\Vad\LiveChunker;
use VadChunker\Vad\SileroModel;
use VadChunker\Vad\VadConfig;

/**
 * Audio in, pause-aligned speech chunks out.
 *
 *   $chunker = new VadChunker($model);            // one per audio stream
 *   foreach ($chunker->feed($pcm) as $chunk) {…}  // call as audio arrives (raw PCM16LE, 16 kHz, mono, any size)
 *   foreach ($chunker->finish() as $chunk) {…}    // end of stream: the rest
 *
 * Chunks are 45–90 s (by default), cut inside speech pauses; silence without speech is dropped. A chunk is
 * returned once 2 s of audio after its cut have arrived, so feed() returns nothing most of the time.
 */
final class VadChunker
{
    private LiveChunker $chunker;
    private string $odd = '';

    public function __construct(private readonly SileroModel $model, private readonly VadConfig $config = new VadConfig())
    {
        $this->chunker = new LiveChunker($model, $config);
    }

    /**
     * @param string $pcm PCM16LE, 16 kHz, mono — continuing the stream
     * @return list<Chunk> chunks that became final
     */
    public function feed(string $pcm): array
    {
        $pcm = $this->odd . $pcm;
        $this->odd = strlen($pcm) % 2 ? substr($pcm, -1) : '';
        if ($this->odd !== '') {
            $pcm = substr($pcm, 0, -1);
        }
        return array_map(Chunk::fromCut(...), $this->chunker->feed($pcm));
    }

    /** Same as feed() for a WAV piece (must be 16 kHz, mono, 16-bit). @return list<Chunk> */
    public function feedWav(string $wav): array
    {
        return $this->feed(Pcm::fromWav($wav));
    }

    /** End of the stream. @return list<Chunk> the remaining chunks (none if only silence is left) */
    public function finish(): array
    {
        $this->odd = '';
        return array_map(Chunk::fromCut(...), $this->chunker->finish());
    }

    /** @return list<array{start: float, end: float, durationS: float}> silent spans (>= silence_report_s) since the last call */
    public function silences(): array
    {
        return $this->chunker->takeSilences();
    }

    /** Seconds of audio received so far. */
    public function elapsedSeconds(): float
    {
        return $this->chunker->elapsedS();
    }

    /** End of the most recent speech, in stream seconds (for "nobody is talking" detection). */
    public function lastSpeechSeconds(): float
    {
        return $this->chunker->lastSpeechS;
    }

    /**
     * Serialize the stream state (≈ up to 3 MB: the audio since the last cut) to continue it in another
     * request/process with restore(). Not needed when one long-running process handles the whole stream.
     */
    public function snapshot(): string
    {
        return serialize(['chunker' => $this->chunker->exportState(), 'odd' => $this->odd]);
    }

    public static function restore(string $snapshot, SileroModel $model, VadConfig $config = new VadConfig()): self
    {
        $s = unserialize($snapshot, ['allowed_classes' => false]);
        if (!is_array($s) || !isset($s['chunker'])) {
            throw new \InvalidArgumentException('Not a VadChunker snapshot');
        }
        $c = new self($model, $config);
        $c->chunker->importState($s['chunker']);
        $c->odd = $s['odd'];
        return $c;
    }

    /**
     * Chunk a whole recording (16 kHz mono 16-bit WAV).
     *
     * @return list<Chunk>
     */
    public static function chunkWav(string $wav, SileroModel $model, VadConfig $config = new VadConfig()): array
    {
        $pcm = Pcm::fromWav($wav);
        $c = new self($model, $config);
        $out = [];
        for ($p = 0, $n = strlen($pcm); $p < $n; $p += 32000) {   // 1 s at a time, as a live stream would arrive
            array_push($out, ...$c->feed(substr($pcm, $p, 32000)));
        }
        array_push($out, ...$c->finish());
        return $out;
    }
}
