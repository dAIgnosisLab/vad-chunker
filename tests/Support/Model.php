<?php

namespace EmrVoice\Tests\Support;

use EmrVoice\Support\Runtime;
use EmrVoice\Vad\SileroModel;

final class Model
{
    public static function get(): SileroModel
    {
        $root = dirname(__DIR__, 2);
        $candidates = array_filter([
            getenv('EMR_VOICE_ORT_LIB') ?: null,
            $root . '/runtime/' . Runtime::libraryName(),
            $root . '/vendor/orchestra/testbench-core/laravel/storage/emr-voice/' . Runtime::libraryName(), // testbench emr-voice:install-runtime
        ]);
        $lib = current(array_filter($candidates, 'is_file')) ?: end($candidates);
        return SileroModel::shared($root . '/resources/models/silero_vad.onnx', $lib);
    }

    public static function fixture(string $name): ?string
    {
        $path = dirname(__DIR__) . '/fixtures/build/' . $name;
        return is_file($path) ? $path : null;
    }
}
