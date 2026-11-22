<?php

namespace VadChunker\Vad;

use VadChunker\Exceptions\VadChunkerException;
use OnnxRuntime\ElementType;
use OnnxRuntime\FFI as OrtFFI;
use OnnxRuntime\InferenceSession;
use OnnxRuntime\OrtValue;

/**
 * Silero VAD v5 (ONNX) at 16 kHz. One session per process, shared by all streams; the
 * recurrent state is owned by each StreamingVad. Tensors are allocated once and refilled
 * with FFI::memcpy per frame.
 */
final class SileroModel
{
    public const FRAME = 512;
    public const CONTEXT = 64;
    public const STATE_FLOATS = 2 * 1 * 128;

    private static ?self $shared = null;

    private InferenceSession $session;
    private OrtValue $input;
    private OrtValue $state;
    private OrtValue $sr;
    private string $outProb;
    private string $outState;

    public static function shared(string $modelPath, ?string $libPath = null): self
    {
        return self::$shared ??= new self($modelPath, $libPath);
    }

    public function __construct(string $modelPath, ?string $libPath = null)
    {
        if (!extension_loaded('ffi')) {
            throw new VadChunkerException('The PHP FFI extension is required (ext-ffi, ffi.enable=true or CLI with ffi.enable=preload).');
        }
        if ($libPath !== null) {
            if (!is_file($libPath)) {
                throw new VadChunkerException("ONNX Runtime library not found at {$libPath}. Run: php artisan vad-chunker:install-runtime");
            }
            OrtFFI::$lib = $libPath;
        }
        if (!is_file($modelPath)) {
            throw new VadChunkerException("Silero model not found at {$modelPath}");
        }

        $this->session = new InferenceSession($modelPath, intraOpNumThreads: 1, interOpNumThreads: 1);
        $outputs = array_map(fn ($o) => $o['name'], $this->session->outputs());
        [$this->outProb, $this->outState] = $outputs;

        $this->input = OrtValue::fromShapeAndType([1, self::CONTEXT + self::FRAME], ElementType::Float);
        $this->state = OrtValue::fromShapeAndType([2, 1, 128], ElementType::Float);
        // Silero declares sr as a scalar; ORT accepts a 1-element tensor and the PHP binding cannot build rank-0 tensors.
        $this->sr = OrtValue::fromShapeAndType([1], ElementType::Int64);
        \FFI::memcpy($this->sr->dataPtr(), pack('q', 16000), 8);
    }

    /**
     * @param string $inputFloats packed float32 (pack('g*')) of CONTEXT + FRAME samples
     * @param string $stateFloats packed float32 recurrent state (STATE_FLOATS)
     * @return array{0: float, 1: string} speech probability and the new packed state
     */
    public function run(string $inputFloats, string $stateFloats): array
    {
        \FFI::memcpy($this->input->dataPtr(), $inputFloats, (self::CONTEXT + self::FRAME) * 4);
        \FFI::memcpy($this->state->dataPtr(), $stateFloats, self::STATE_FLOATS * 4);

        [$prob, $state] = $this->session->runWithOrtValues(
            [$this->outProb, $this->outState],
            ['input' => $this->input, 'state' => $this->state, 'sr' => $this->sr]
        );

        return [
            unpack('g', \FFI::string($prob->dataPtr(), 4))[1],
            \FFI::string($state->dataPtr(), self::STATE_FLOATS * 4),
        ];
    }
}
