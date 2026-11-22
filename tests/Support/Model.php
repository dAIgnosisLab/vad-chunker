<?php

namespace VadChunker\Tests\Support;

use VadChunker\Support\Runtime;
use VadChunker\Vad\SileroModel;

final class Model
{
    public static function get(): SileroModel
    {
        return SileroModel::shared(dirname(__DIR__, 2) . '/resources/models/silero_vad.onnx', self::libPath());
    }

    public static function libPath(): string
    {
        $root = dirname(__DIR__, 2);
        $candidates = array_filter([
            getenv('VAD_CHUNKER_ORT_LIB') ?: null,
            $root . '/runtime/' . Runtime::libraryName(),
            $root . '/vendor/orchestra/testbench-core/laravel/storage/vad-chunker/' . Runtime::libraryName(), // testbench vad-chunker:install-runtime
        ]);
        return current(array_filter($candidates, 'is_file')) ?: end($candidates);
    }

    public static function fixture(string $name): ?string
    {
        $path = dirname(__DIR__) . '/fixtures/build/' . $name;
        return is_file($path) ? $path : null;
    }
}
