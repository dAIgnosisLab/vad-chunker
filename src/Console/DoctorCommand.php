<?php

namespace EmrVoice\Console;

use EmrVoice\EmrVoiceManager;
use EmrVoice\Vad\StreamingVad;
use EmrVoice\Vad\SileroModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class DoctorCommand extends Command
{
    protected $signature = 'emr-voice:doctor';

    protected $description = 'Check that this server can run emr-voice';

    private bool $ok = true;

    public function handle(EmrVoiceManager $manager): int
    {
        $c = config('emr-voice');

        $this->check('PHP >= 8.1', PHP_VERSION_ID >= 80100, PHP_VERSION);
        $ffi = extension_loaded('ffi');
        $mode = (string) ini_get('ffi.enable');
        $this->check('ext-ffi loaded', $ffi, $ffi ? "ffi.enable={$mode}" : 'install/enable the ffi extension');
        $this->check('FFI usable from the CLI (emr-voice:serve)', $ffi && in_array($mode, ['1', 'true', 'On', 'preload'], true), "ffi.enable={$mode}");
        $this->check('pcntl (graceful restarts)', function_exists('pcntl_signal'), 'optional', warnOnly: true);

        $lib = $manager->runtimePath();
        $this->check('ONNX Runtime library', is_file($lib), is_file($lib) ? $lib : "missing: run php artisan emr-voice:install-runtime");
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

        $this->check('auth secret', (string) $c['auth']['secret'] !== '', 'EMR_VOICE_SECRET or APP_KEY');
        $this->check('EMR API key', (string) $c['api']['key'] !== '', 'EMR_VOICE_API_KEY');
        try {
            $store = Cache::store($c['store']['cache_store']);
            $store->put('emr-voice:doctor', 1, 10);
            $this->check('cache store', $store->get('emr-voice:doctor') === 1, $c['store']['cache_store'] ?? config('cache.default'));
            $this->check('shared cache (several servers)', !in_array(config('cache.stores.' . ($c['store']['cache_store'] ?? config('cache.default')) . '.driver'), ['array', 'file'], true),
                'use redis/database/memcached when you run more than one server', warnOnly: true);
        } catch (\Throwable $e) {
            $this->check('cache store', false, $e->getMessage());
        }
        try {
            $r = Http::timeout(10)->get(rtrim($c['api']['base_url'], '/') . '/health');
            $this->check('EMR API reachable', $r->successful(), $c['api']['base_url'] . ' → HTTP ' . $r->status());
        } catch (\Throwable $e) {
            $this->check('EMR API reachable', false, $e->getMessage());
        }
        if ($c['poll_result']['enabled']) {
            $conn = $c['poll_result']['connection'] ?? config('queue.default');
            $this->check('queue for result polling', config("queue.connections.$conn.driver") !== 'sync', "connection {$conn} (run a queue worker)", warnOnly: true);
        }

        $this->newLine();
        $this->ok ? $this->info('Ready.') : $this->error('Fix the items marked ✗.');
        return $this->ok ? self::SUCCESS : self::FAILURE;
    }

    private function check(string $what, bool $pass, string $detail = '', bool $warnOnly = false): void
    {
        $mark = $pass ? '<fg=green>✓</>' : ($warnOnly ? '<fg=yellow>!</>' : '<fg=red>✗</>');
        $this->line(sprintf('  %s %-42s %s', $mark, $what, $detail));
        if (!$pass && !$warnOnly) {
            $this->ok = false;
        }
    }
}
