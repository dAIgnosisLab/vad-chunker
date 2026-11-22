<?php

namespace EmrVoice;

use EmrVoice\Auth\TokenSigner;
use EmrVoice\Client\EmrApiClient;
use EmrVoice\Exceptions\EmrVoiceException;
use EmrVoice\Recording\RecordingStore;
use EmrVoice\Support\Runtime;
use EmrVoice\Vad\VadConfig;

/** Behind the EmrVoice facade. */
class EmrVoiceManager
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly TokenSigner $tokens,
        private readonly RecordingStore $store,
        private readonly EmrApiClient $api,
    ) {
    }

    /**
     * A short-lived token for the browser to open wss://…/vad?token=… for one recording. Issue it from your own
     * authenticated route: it decides which patient and doctor the audio belongs to, not the browser.
     *
     * @param string $recordingId your id for this recording (e.g. the consultation id); reconnects reuse it
     * @param array<string, mixed>|null $format optional EMR output format, passed to the EMR API
     */
    public function token(string $recordingId, string $patientId, string $doctorId, ?string $hospitalId = null, ?array $format = null, ?int $ttlSeconds = null): string
    {
        $claims = array_filter(['rid' => $recordingId, 'pid' => $patientId, 'did' => $doctorId, 'hid' => $hospitalId ?? $this->config['hospital_id'], 'fmt' => $format],
            fn ($v) => $v !== null);
        return $this->tokens->sign($claims, $ttlSeconds ?? $this->config['auth']['token_ttl_s']);
    }

    /** @return array<string, mixed>|null stored state: sessionId, status (active|finishing|finished|cancelled|failed), nextIndex, … */
    public function recording(string $recordingId): ?array
    {
        return $this->store->get($recordingId);
    }

    /** @return array{status: string, emr: mixed} the EMR once status is "completed" */
    public function result(string $recordingId): array
    {
        $state = $this->store->get($recordingId);
        if (!isset($state['sessionId'])) {
            throw new EmrVoiceException("Unknown recording {$recordingId}");
        }
        return $this->api->result($state['sessionId']);
    }

    public function vadConfig(): VadConfig
    {
        return VadConfig::fromArray($this->config['vad']);
    }

    public function modelPath(): string
    {
        return $this->config['model_path'] ?? dirname(__DIR__) . '/resources/models/silero_vad.onnx';
    }

    public function runtimePath(): string
    {
        return $this->config['runtime_path'] ?? storage_path('emr-voice/' . Runtime::libraryName());
    }
}
