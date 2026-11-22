<?php

namespace EmrVoice\Recording;

use EmrVoice\Client\AsyncEmrApiClient;
use EmrVoice\Events\RecordingStarted;
use EmrVoice\Vad\LiveChunker;
use EmrVoice\Vad\SileroModel;
use EmrVoice\Vad\VadConfig;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/** Starts a new recording (creating our EMR session) or rebuilds one from the store after a server restart. */
final class RecordingFactory
{
    /** @var array<string, PromiseInterface> */
    private array $pending = [];

    /** @param array{backoff_s: list<int|float>, warn_after_attempts: int} $upload */
    public function __construct(
        private readonly SileroModel $model,
        private readonly VadConfig $vad,
        private readonly AsyncEmrApiClient $api,
        private readonly RecordingStore $store,
        private readonly LoopInterface $loop,
        private readonly Dispatcher $events,
        private readonly LoggerInterface $log,
        private readonly array $upload,
        private readonly ?string $defaultHospitalId,
    ) {
    }

    /**
     * @param array<string, mixed> $claims verified token claims: rid, pid, did, [hid], [fmt]
     * @return PromiseInterface<Recording|null> null when the recording already finished or was cancelled
     */
    public function open(string $recordingId, array $claims): PromiseInterface
    {
        return $this->pending[$recordingId] ??= $this->doOpen($recordingId, $claims)->finally(function () use ($recordingId) {
            unset($this->pending[$recordingId]);
        });
    }

    private function doOpen(string $recordingId, array $claims): PromiseInterface
    {
        $state = $this->store->get($recordingId);
        if ($state && in_array($state['status'] ?? null, [RecordingStore::FINISHED, RecordingStore::CANCELLED, 'failed'], true)) {
            return resolve(null);
        }
        if ($state && isset($state['sessionId'])) {
            $this->log->info('emr-voice: resuming recording from store', ['recordingId' => $recordingId, 'ackedBytes' => $state['ackedBytes'] ?? 0, 'nextIndex' => $state['nextIndex'] ?? 0]);
            return resolve($this->make($recordingId, $state['sessionId'], (int) ($state['nextIndex'] ?? 0), (int) ($state['ackedBytes'] ?? 0)));
        }

        $hospitalId = (string) ($claims['hid'] ?? $this->defaultHospitalId ?? '');
        $patientId = (string) $claims['pid'];
        $doctorId = (string) $claims['did'];
        return $this->api->createSession($hospitalId, $doctorId, $patientId, $claims['fmt'] ?? null)
            ->then(function (string $sessionId) use ($recordingId, $hospitalId, $patientId, $doctorId) {
                $this->store->put($recordingId, [
                    'sessionId' => $sessionId, 'nextIndex' => 0, 'ackedBytes' => 0, 'status' => RecordingStore::ACTIVE,
                    'patientId' => $patientId, 'doctorId' => $doctorId, 'hospitalId' => $hospitalId, 'startedAt' => time(),
                ]);
                $this->log->info('emr-voice: recording started', ['recordingId' => $recordingId, 'sessionId' => $sessionId]);
                try {
                    $this->events->dispatch(new RecordingStarted($recordingId, $sessionId, $patientId, $doctorId, $hospitalId));
                } catch (\Throwable $e) {
                    $this->log->error('emr-voice: event listener failed', ['event' => RecordingStarted::class, 'recordingId' => $recordingId, 'error' => $e->getMessage()]);
                }
                return $this->make($recordingId, $sessionId, 0, 0);
            });
    }

    private function make(string $recordingId, string $sessionId, int $nextIndex, int $resumeBytes): Recording
    {
        return new Recording(
            $recordingId, $sessionId, $nextIndex, $resumeBytes,
            new LiveChunker($this->model, $this->vad, intdiv($resumeBytes, 2)),
            $this->api, $this->store, $this->loop, $this->events, $this->log,
            $this->upload['backoff_s'], $this->upload['warn_after_attempts'],
        );
    }
}
