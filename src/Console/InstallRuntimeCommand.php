<?php

namespace VadChunker\Console;

use Illuminate\Console\Command;
use VadChunker\ChunkerManager;
use VadChunker\Support\Runtime;

final class InstallRuntimeCommand extends Command
{
    protected $signature = 'vad-chunker:install-runtime {--force : Download again even if present}';

    protected $description = 'Download the ONNX Runtime library used for voice activity detection';

    public function handle(ChunkerManager $manager): int
    {
        $target = $manager->runtimePath();
        if (is_file($target) && !$this->option('force')) {
            $this->info("ONNX Runtime already installed: {$target}");
            return self::SUCCESS;
        }
        $this->line('Downloading ONNX Runtime ' . Runtime::VERSION . ' (' . Runtime::platform() . ')…');
        Runtime::install($target);
        $this->info("Installed {$target}");
        return self::SUCCESS;
    }
}
