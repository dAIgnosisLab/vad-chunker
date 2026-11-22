<?php

namespace VadChunker\Console;

use Illuminate\Console\Command;
use VadChunker\ChunkerManager;
use VadChunker\Vad\SileroModel;
use VadChunker\Vad\StreamingVad;

final class DoctorCommand extends Command
{
    protected $signature = 'vad-chunker:doctor';

    protected $description = 'Check that this PHP can run the VAD chunker';

    private bool $ok = true;

    public function handle(ChunkerManager $manager): int
    {
        $ffi = extension_loaded('ffi');
        $mode = (string) ini_get('ffi.enable');
        $this->check('PHP >= 8.1', PHP_VERSION_ID >= 80100, PHP_VERSION);
        $this->check('ext-ffi loaded', $ffi, $ffi ? "ffi.enable={$mode}" : 'install/enable the ffi extension');
        $this->check('FFI enabled for this SAPI', $ffi && in_array($mode, ['1', 'true', 'On', 'preload'], true),
            $mode === 'preload' ? 'preload: works in CLI processes; set ffi.enable=true to use it in PHP-FPM' : "ffi.enable={$mode}");

        $lib = $manager->runtimePath();
        $this->check('ONNX Runtime library', is_file($lib), is_file($lib) ? $lib : 'missing: run php artisan vad-chunker:install-runtime');
        $this->check('Silero model', is_file($manager->modelPath()), $manager->modelPath());

        if ($ffi && is_file($lib)) {
            try {
                $t = microtime(true);
                $model = SileroModel::shared($manager->modelPath(), $lib);
                $loaded = microtime(true);
                $probs = (new StreamingVad($model))->feed(str_repeat("\0", 16000 * 2));
                $this->check('VAD inference', count($probs) === 31, sprintf('model loaded in %.0f ms, 1 s of audio in %.1f ms',
                    ($loaded - $t) * 1000, (microtime(true) - $loaded) * 1000));
            } catch (\Throwable $e) {
                $this->check('VAD inference', false, $e->getMessage());
            }
        }

        $this->newLine();
        $this->ok ? $this->info('Ready.') : $this->error('Fix the items marked ✗.');
        return $this->ok ? self::SUCCESS : self::FAILURE;
    }

    private function check(string $what, bool $pass, string $detail = ''): void
    {
        $this->line(sprintf('  %s %-28s %s', $pass ? '<fg=green>✓</>' : '<fg=red>✗</>', $what, $detail));
        $this->ok = $this->ok && $pass;
    }
}
