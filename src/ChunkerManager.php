<?php

namespace VadChunker;

use VadChunker\Support\Runtime;
use VadChunker\Vad\SileroModel;
use VadChunker\Vad\VadConfig;

/** Laravel entry point (behind the VadChunker facade): builds chunkers from config/vad-chunker.php. */
class ChunkerManager
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config, private readonly string $storagePath)
    {
    }

    /** A chunker for one new audio stream. @param array<string, mixed> $vad overrides for config('vad-chunker.vad') */
    public function make(array $vad = []): VadChunker
    {
        return new VadChunker($this->model(), $this->vadConfig($vad));
    }

    /** Continue a stream saved with VadChunker::snapshot(). */
    public function restore(string $snapshot, array $vad = []): VadChunker
    {
        return VadChunker::restore($snapshot, $this->model(), $this->vadConfig($vad));
    }

    /** Chunk a whole 16 kHz mono 16-bit WAV recording. @return list<Chunk> */
    public function chunkWav(string $wav, array $vad = []): array
    {
        return VadChunker::chunkWav($wav, $this->model(), $this->vadConfig($vad));
    }

    public function model(): SileroModel
    {
        return SileroModel::shared($this->modelPath(), $this->runtimePath());
    }

    public function vadConfig(array $overrides = []): VadConfig
    {
        return VadConfig::fromArray($overrides + $this->config['vad']);
    }

    public function modelPath(): string
    {
        return $this->config['model_path'] ?? dirname(__DIR__) . '/resources/models/silero_vad.onnx';
    }

    public function runtimePath(): string
    {
        return $this->config['runtime_path'] ?? $this->storagePath . '/vad-chunker/' . Runtime::libraryName();
    }
}
