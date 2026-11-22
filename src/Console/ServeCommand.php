<?php

namespace EmrVoice\Console;

use EmrVoice\Auth\TokenSigner;
use EmrVoice\Client\AsyncEmrApiClient;
use EmrVoice\EmrVoiceManager;
use EmrVoice\Recording\RecordingFactory;
use EmrVoice\Recording\RecordingStore;
use EmrVoice\Server\WebSocketServer;
use EmrVoice\Vad\SileroModel;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;

final class ServeCommand extends Command
{
    protected $signature = 'emr-voice:serve {--host= : Interface to bind (default from config)} {--port= : Port (default from config)}';

    protected $description = 'Run the live-recording WebSocket server (VAD chunking + upload to the EMR API)';

    public function handle(EmrVoiceManager $manager, TokenSigner $tokens, RecordingStore $store, Dispatcher $events): int
    {
        $config = config('emr-voice');
        $server = $config['server'];
        $server['host'] = $this->option('host') ?: $server['host'];
        $server['port'] = (int) ($this->option('port') ?: $server['port']);
        $log = app('log')->channel($config['log_channel']);
        \assert($log instanceof LoggerInterface);

        $model = SileroModel::shared($manager->modelPath(), $manager->runtimePath());
        $loop = Loop::get();
        $api = new AsyncEmrApiClient($loop, $config['api']['base_url'], $config['api']['key'], $config['api']['timeout']);
        $factory = new RecordingFactory($model, $manager->vadConfig(), $api, $store, $loop, $events, $log, $config['upload'], $config['hospital_id']);
        $ws = new WebSocketServer($loop, $server, $tokens, $factory, $log);

        $ws->listen();
        $this->info("emr-voice listening on ws://{$server['host']}:{$server['port']}{$server['path']}");

        if (function_exists('pcntl_signal')) {
            $shutdown = function (int $signal) use ($loop, $ws) {
                $this->warn('Stopping: browsers will reconnect and resume from their last ack.');
                $ws->stop();
                $loop->addTimer(10, fn () => $loop->stop());
                $loop->addPeriodicTimer(0.25, function () use ($loop, $ws) {
                    foreach ($ws->recordings() as $rec) {
                        if ($rec->hasPendingUploads()) {
                            return;
                        }
                    }
                    $loop->stop();
                });
            };
            $loop->addSignal(SIGTERM, $shutdown);
            $loop->addSignal(SIGINT, $shutdown);
        }

        $loop->run();
        return self::SUCCESS;
    }
}
