<?php

namespace VadChunker;

use Illuminate\Support\ServiceProvider;
use VadChunker\Console\DoctorCommand;
use VadChunker\Console\InstallRuntimeCommand;

class VadChunkerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/vad-chunker.php', 'vad-chunker');
        $this->app->singleton(ChunkerManager::class, fn ($app) => new ChunkerManager($app['config']['vad-chunker'], $app->storagePath()));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/vad-chunker.php' => config_path('vad-chunker.php')], 'vad-chunker-config');
            $this->commands([InstallRuntimeCommand::class, DoctorCommand::class]);
        }
    }
}
