<?php

namespace EmrVoice;

use EmrVoice\Auth\TokenSigner;
use EmrVoice\Client\EmrApiClient;
use EmrVoice\Console\DoctorCommand;
use EmrVoice\Console\InstallRuntimeCommand;
use EmrVoice\Console\ServeCommand;
use EmrVoice\Events\RecordingFinished;
use EmrVoice\Jobs\PollResult;
use EmrVoice\Recording\RecordingStore;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;

class EmrVoiceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/emr-voice.php', 'emr-voice');

        $this->app->singleton(TokenSigner::class, fn ($app) => new TokenSigner((string) $app['config']['emr-voice.auth.secret']));
        $this->app->singleton(RecordingStore::class, fn ($app) => new RecordingStore(
            $app['cache']->store($app['config']['emr-voice.store.cache_store']),
            (int) $app['config']['emr-voice.store.ttl_s'],
        ));
        $this->app->singleton(EmrApiClient::class, fn ($app) => new EmrApiClient(
            $app['Illuminate\Http\Client\Factory'],
            $app['config']['emr-voice.api.base_url'],
            $app['config']['emr-voice.api.key'],
            (float) $app['config']['emr-voice.api.timeout'],
        ));
        $this->app->singleton(EmrVoiceManager::class, fn ($app) => new EmrVoiceManager(
            $app['config']['emr-voice'],
            $app[TokenSigner::class],
            $app[RecordingStore::class],
            $app[EmrApiClient::class],
        ));
    }

    public function boot(Dispatcher $events): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/emr-voice.php' => config_path('emr-voice.php')], 'emr-voice-config');
            $this->publishes([__DIR__ . '/../resources/js' => public_path('vendor/emr-voice')], 'emr-voice-assets');
            $this->commands([ServeCommand::class, InstallRuntimeCommand::class, DoctorCommand::class]);
        }

        $events->listen(RecordingFinished::class, function (RecordingFinished $e) {
            $c = $this->app['config']['emr-voice.poll_result'];
            if (!$c['enabled']) {
                return;
            }
            $connection = $c['connection'] ?? $this->app['config']['queue.default'];
            if ($this->app['config']["queue.connections.$connection.driver"] === 'sync') {
                // a sync queue would run the polling loop inside the WebSocket server and block it
                $this->app['log']->channel($this->app['config']['emr-voice.log_channel'])
                    ->warning('emr-voice: result polling skipped, queue connection is sync', ['recordingId' => $e->recordingId]);
                return;
            }
            $job = new PollResult($e->recordingId, $e->sessionId, time() + (int) $c['timeout_s'], (int) $c['interval_s']);
            $job->onConnection($c['connection'])->onQueue($c['queue'])->delay((int) $c['interval_s']);
            $this->app[Bus::class]->dispatch($job);
        });
    }
}
