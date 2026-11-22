<?php

namespace EmrVoice\Jobs;

use EmrVoice\Client\ApiError;
use EmrVoice\Client\EmrApiClient;
use EmrVoice\Events\EmrFailed;
use EmrVoice\Events\EmrReady;
use EmrVoice\Recording\RecordingStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Polls the EMR API after a recording finished and fires EmrReady / EmrFailed. Re-queues itself until done. */
final class PollResult implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $recordingId,
        public readonly string $sessionId,
        public readonly int $deadline,
        public readonly int $interval,
    ) {
    }

    public function handle(EmrApiClient $api, RecordingStore $store, Dispatcher $events, Bus $bus): void
    {
        try {
            $r = $api->result($this->sessionId);
        } catch (ApiError $e) {
            if ($e->isPermanent()) {
                $store->update($this->recordingId, ['emrStatus' => 'failed']);
                $events->dispatch(new EmrFailed($this->recordingId, $this->sessionId, 'failed'));
                return;
            }
            $r = ['status' => 'pending'];
        } catch (\Throwable) {
            $r = ['status' => 'pending'];
        }

        $status = strtolower((string) ($r['status'] ?? ''));
        if ($status === 'completed') {
            $store->update($this->recordingId, ['emrStatus' => 'completed']);
            $events->dispatch(new EmrReady($this->recordingId, $this->sessionId, $r['emr'] ?? null));
            return;
        }
        if (in_array($status, ['no_speech', 'cancelled', 'failed'], true)) {
            $store->update($this->recordingId, ['emrStatus' => $status]);
            $events->dispatch(new EmrFailed($this->recordingId, $this->sessionId, $status));
            return;
        }
        if (time() >= $this->deadline) {
            $store->update($this->recordingId, ['emrStatus' => 'timeout']);
            $events->dispatch(new EmrFailed($this->recordingId, $this->sessionId, 'timeout'));
            return;
        }
        $next = new self($this->recordingId, $this->sessionId, $this->deadline, $this->interval);
        $next->connection = $this->connection;
        $next->queue = $this->queue;
        $bus->dispatch($next->delay($this->interval));
    }
}
