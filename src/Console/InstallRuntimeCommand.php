<?php

namespace EmrVoice\Console;

use EmrVoice\EmrVoiceManager;
use EmrVoice\Support\Runtime;
use Illuminate\Console\Command;

final class InstallRuntimeCommand extends Command
{
    protected $signature = 'emr-voice:install-runtime {--force : Download again even if present}';

    protected $description = 'Download the ONNX Runtime library used for voice activity detection';

    public function handle(EmrVoiceManager $manager): int
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
