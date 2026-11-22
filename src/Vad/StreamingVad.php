<?php

namespace VadChunker\Vad;

/** Port of StreamingVAD (EMR_websocket/src/audio/vad.js): recurrent state, 64-sample context and partial frame kept between feed() calls. */
final class StreamingVad
{
    private const FRAME_BYTES = SileroModel::FRAME * 2;
    private const CONTEXT_BYTES = SileroModel::CONTEXT * 2;

    private string $state;
    private string $context;
    public string $rest = '';

    public function __construct(private readonly SileroModel $model)
    {
        $this->state = str_repeat(pack('g', 0.0), SileroModel::STATE_FLOATS);
        $this->context = str_repeat("\0", self::CONTEXT_BYTES);
    }

    /** @return array{state: string, context: string, rest: string} binary strings */
    public function exportState(): array
    {
        return ['state' => $this->state, 'context' => $this->context, 'rest' => $this->rest];
    }

    /** @param array{state: string, context: string, rest: string} $s */
    public function importState(array $s): void
    {
        ['state' => $this->state, 'context' => $this->context, 'rest' => $this->rest] = $s;
    }

    /**
     * @param string $pcm Int16LE samples
     * @return list<float> one speech probability per complete 512-sample frame
     */
    public function feed(string $pcm): array
    {
        $all = $this->rest . $pcm;
        $n = intdiv(strlen($all), self::FRAME_BYTES);
        $out = [];
        for ($k = 0; $k < $n; $k++) {
            $frame = substr($all, $k * self::FRAME_BYTES, self::FRAME_BYTES);
            $input = $this->context . $frame;
            $floats = [];
            foreach (unpack('s*', $input) as $v) {
                $floats[] = $v / 32768.0;
            }
            [$out[], $this->state] = $this->model->run(pack('g*', ...$floats), $this->state);
            $this->context = substr($input, -self::CONTEXT_BYTES);
        }
        $this->rest = substr($all, $n * self::FRAME_BYTES);
        return $out;
    }
}
