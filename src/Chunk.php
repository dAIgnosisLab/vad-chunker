<?php

namespace VadChunker;

use VadChunker\Audio\Pcm;

/** One pause-aligned piece of speech. Times are seconds since the stream began. */
final class Chunk
{
    public function __construct(
        public readonly int $index,
        /** stream span this chunk covers (start of the stream position, end of the cut) */
        public readonly float $start,
        public readonly float $end,
        /** why it was cut: pause | long-pause | quietest-point | end */
        public readonly string $reason,
        /** share of 32 ms frames that are speech */
        public readonly float $speechRatio,
        public readonly float $longestSpeechS,
        /** span the audio actually contains, after trimming long leading/trailing silence */
        public readonly float $speechStart,
        public readonly float $speechEnd,
        public readonly float $trimmedS,
        /** PCM16LE, 16 kHz, mono */
        public readonly string $pcm,
    ) {
    }

    /** @param array{chunk: array<string, mixed>, audio: string} $cut */
    public static function fromCut(array $cut): self
    {
        $c = $cut['chunk'];
        return new self($c['index'], $c['start'], $c['end'], $c['reason'], $c['speechRatio'], $c['longestSpeechS'],
            $c['speechStart'], $c['speechEnd'], $c['trimmedS'], $cut['audio']);
    }

    /** 16 kHz mono 16-bit WAV file contents. */
    public function wav(): string
    {
        return Pcm::toWav($this->pcm);
    }

    public function seconds(): float
    {
        return Pcm::seconds($this->pcm);
    }

    /** Metadata without the audio. */
    public function toArray(): array
    {
        return [
            'index' => $this->index, 'start' => $this->start, 'end' => $this->end, 'reason' => $this->reason,
            'speechRatio' => $this->speechRatio, 'longestSpeechS' => $this->longestSpeechS,
            'speechStart' => $this->speechStart, 'speechEnd' => $this->speechEnd, 'trimmedS' => $this->trimmedS,
            'seconds' => $this->seconds(),
        ];
    }
}
