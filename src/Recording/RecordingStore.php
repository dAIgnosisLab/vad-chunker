<?php

namespace EmrVoice\Recording;

use Illuminate\Contracts\Cache\Repository;

/**
 * Per-recording state that must survive the WebSocket process: our EMR session id, the next chunk index,
 * and how much of the stream is safely handled (uploaded or dropped as silence) so a reconnect can resume.
 *
 * Keys: recordingId (their id) → {sessionId, nextIndex, ackedBytes, status, patientId, doctorId, hospitalId, ...}
 */
final class RecordingStore
{
    public const ACTIVE = 'active';
    public const FINISHING = 'finishing';
    public const FINISHED = 'finished';
    public const CANCELLED = 'cancelled';

    public function __construct(private readonly Repository $cache, private readonly int $ttl)
    {
    }

    /** @return array<string, mixed>|null */
    public function get(string $recordingId): ?array
    {
        $v = $this->cache->get($this->key($recordingId));
        return is_array($v) ? $v : null;
    }

    /** @param array<string, mixed> $state */
    public function put(string $recordingId, array $state): void
    {
        $this->cache->put($this->key($recordingId), $state + ['updatedAt' => time()], $this->ttl);
    }

    /** @param array<string, mixed> $changes */
    public function update(string $recordingId, array $changes): array
    {
        $state = array_merge($this->get($recordingId) ?? [], $changes, ['updatedAt' => time()]);
        $this->cache->put($this->key($recordingId), $state, $this->ttl);
        return $state;
    }

    private function key(string $recordingId): string
    {
        return 'emr-voice:recording:' . $recordingId;
    }
}
