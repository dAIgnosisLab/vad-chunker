<?php

namespace EmrVoice\Recording;

use EmrVoice\Audio\Pcm;
use EmrVoice\Client\ApiError;
use EmrVoice\Client\AsyncEmrApiClient;
use EmrVoice\Events\ChunkUploaded;
use EmrVoice\Events\RecordingCancelled;
use EmrVoice\Events\RecordingFinished;
use EmrVoice\Events\SilenceDetected;
use EmrVoice\Events\UploadFailed;
use EmrVoice\Vad\LiveChunker;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;

/**
 * One live recording: continuous PCM16 in → pause-cut WAV chunks out to our EMR API, strictly in order.
 *
 * Stream positions are byte offsets of the PCM stream. The browser keeps audio until it is acked; the ack
 * is the point before which everything is either uploaded or dropped as silence, so after a reconnect (or a
 * server restart, from the RecordingStore) the browser resends from the ack and nothing is lost. Each chunk
 * is requested with an explicit chunkIndex, which the API treats idempotently, so retries never leave gaps.
 */
final class Recording
{
    private const ACK_STEP_BYTES = 32000; // ack at least every second of handled audio

    private ?Peer $peer = null;
    /** @var list<array{pcm: string, isFinal: bool, vad: ?array, startByte: int}> */
    private array $queue = [];
    private bool $uploading = false;
    private int $received;
    private int $expect;
    private int $ackedBytes;
    private string $oddByte = '';
    private bool $finishing = false;
    private bool $done = false;
    private bool $autoFinish = false;
    public float $lastAudioAt;
    /** @var callable(self): void|null */
    public $onDone = null;

    /**
     * @param list<int|float> $backoff seconds between upload attempts; the last value repeats
     */
    public function __construct(
        public readonly string $recordingId,
        public readonly string $sessionId,
        private int $nextIndex,
        int $resumeBytes,
        private readonly LiveChunker $chunker,
        private readonly AsyncEmrApiClient $api,
        private readonly RecordingStore $store,
        private readonly LoopInterface $loop,
        private readonly Dispatcher $events,
        private readonly LoggerInterface $log,
        private readonly array $backoff = [1, 2, 5, 10, 30],
        private readonly int $warnAfterAttempts = 5,
    ) {
        $this->received = $this->expect = $this->ackedBytes = $resumeBytes;
        $this->chunker->count = $nextIndex;
        $this->lastAudioAt = microtime(true);
    }

    public function isConnected(): bool
    {
        return $this->peer !== null;
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    public function isFinishing(): bool
    {
        return $this->finishing;
    }

    public function hasPendingUploads(): bool
    {
        return $this->queue !== [] && !$this->done;
    }

    public function attach(Peer $peer): void
    {
        $old = $this->peer;
        $this->peer = $peer;
        $old?->close(4000, 'replaced by a newer connection');
        $this->expect = $this->ackedBytes;
        $this->oddByte = '';
        $this->lastAudioAt = microtime(true);
        $peer->send(['type' => 'ready', 'resumeOffset' => $this->ackedBytes, 'sampleRate' => Pcm::SAMPLE_RATE, 'finishing' => $this->finishing]);
    }

    public function detach(Peer $peer): void
    {
        if ($this->peer === $peer) {
            $this->peer = null;
        }
    }

    /** Raw PCM16LE bytes, continuing the stream from where the browser was told to resume. */
    public function append(string $bytes): void
    {
        if ($this->finishing || $this->done || $bytes === '') {
            return;
        }
        $this->lastAudioAt = microtime(true);
        $pos = $this->expect;
        $this->expect += strlen($bytes);
        if ($this->expect <= $this->received) {
            return; // already have all of it (resent after a reconnect)
        }
        if ($pos < $this->received) {
            $bytes = substr($bytes, $this->received - $pos);
        }
        $this->received += strlen($bytes);

        $bytes = $this->oddByte . $bytes;
        if (strlen($bytes) % 2) {
            $this->oddByte = substr($bytes, -1);
            $bytes = substr($bytes, 0, -1);
        } else {
            $this->oddByte = '';
        }

        foreach ($this->chunker->feed($bytes) as $cut) {
            $this->enqueue($cut['audio'], false, $cut['chunk'], $cut['startSample'] * 2);
        }
        $this->reportSilences();
        $this->maybeAck();
    }

    /** End of the stream: the rest becomes the last chunk(s), the final one flagged so the EMR is generated. */
    public function finish(bool $auto = false): void
    {
        if ($this->finishing || $this->done) {
            return;
        }
        $this->finishing = true;
        $this->autoFinish = $auto;
        $this->persist(['status' => RecordingStore::FINISHING]);

        $cuts = $this->chunker->finish();
        $this->reportSilences();
        if ($cuts === []) {
            // Nothing left with speech: a 0.1 s silent final chunk still closes the session (as live-vad-chunker.js does).
            $this->enqueue(str_repeat("\0", 3200), true, null, $this->chunker->offset * 2);
        }
        foreach ($cuts as $i => $cut) {
            $this->enqueue($cut['audio'], $i === array_key_last($cuts), $cut['chunk'], $cut['startSample'] * 2);
        }
        $this->peer?->send(['type' => 'finishing', 'pendingChunks' => count($this->queue)]);
    }

    /** Discard the recording: nothing more is uploaded and no final chunk is sent, so no EMR is generated. */
    public function cancel(): void
    {
        if ($this->done) {
            return;
        }
        $this->queue = $this->uploading ? [$this->queue[0]] : [];
        $this->persist(['status' => RecordingStore::CANCELLED]);
        $this->fire(new RecordingCancelled($this->recordingId, $this->sessionId));
        $this->log->info('emr-voice: recording cancelled', ['recordingId' => $this->recordingId, 'sessionId' => $this->sessionId]);
        $this->end(['type' => 'done', 'cancelled' => true]);
    }

    private function enqueue(string $pcm, bool $isFinal, ?array $vad, int $startByte): void
    {
        $this->queue[] = compact('pcm', 'isFinal', 'vad', 'startByte');
        $this->pump();
    }

    private function pump(): void
    {
        if ($this->uploading || $this->queue === [] || $this->done) {
            return;
        }
        $this->uploading = true;
        $this->attempt($this->queue[0], 1);
    }

    private function attempt(array $item, int $n): void
    {
        $index = $this->nextIndex;
        $this->api->chunkUrl($this->sessionId, $index, $item['isFinal'])
            ->then(fn (array $r) => $this->api->upload($r['uploadUrl'], Pcm::toWav($item['pcm'])))
            ->then(
                fn () => $this->uploaded($item, $index),
                fn (\Throwable $e) => $this->uploadError($item, $index, $n, $e)
            )
            ->catch(fn (\Throwable $e) => $this->log->error('emr-voice: unexpected error after upload', [
                'recordingId' => $this->recordingId, 'chunkIndex' => $index, 'error' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine(),
            ]));
    }

    /** Listeners are the host app's code: a failing one must not break the recording. */
    private function fire(object $event): void
    {
        try {
            $this->events->dispatch($event);
        } catch (\Throwable $e) {
            $this->log->error('emr-voice: event listener failed', ['event' => $event::class, 'recordingId' => $this->recordingId, 'error' => $e->getMessage()]);
        }
    }

    /** The store only serves resume and status queries; recording continues if it is briefly unavailable. */
    private function persist(array $changes): void
    {
        try {
            $this->store->update($this->recordingId, $changes);
        } catch (\Throwable $e) {
            $this->log->warning('emr-voice: could not save recording state', ['recordingId' => $this->recordingId, 'error' => $e->getMessage()]);
        }
    }

    private function uploaded(array $item, int $index): void
    {
        array_shift($this->queue);
        $this->uploading = false;
        $this->nextIndex = $index + 1;
        $this->persist(['nextIndex' => $this->nextIndex]);
        $vad = $item['vad'] ?? ['silentFinal' => true];
        $this->log->info('emr-voice: chunk uploaded', ['recordingId' => $this->recordingId, 'chunkIndex' => $index, 'isFinal' => $item['isFinal'],
            'start' => $vad['start'] ?? null, 'end' => $vad['end'] ?? null, 'reason' => $vad['reason'] ?? null, 'speechRatio' => $vad['speechRatio'] ?? null]);
        $this->fire(new ChunkUploaded($this->recordingId, $this->sessionId, $index, $item['isFinal'], $vad));
        $this->peer?->send(['type' => 'chunk', 'index' => $index, 'isFinal' => $item['isFinal']]
            + array_intersect_key($vad, array_flip(['start', 'end', 'reason', 'speechRatio'])));
        $this->maybeAck(true);

        if ($this->done) {
            return; // cancelled while this upload was in flight
        }
        if ($item['isFinal']) {
            $this->complete();
            return;
        }
        $this->pump();
    }

    private function uploadError(array $item, int $index, int $n, \Throwable $e): void
    {
        $permanent = $e instanceof ApiError && $e->isPermanent();
        $this->log->warning('emr-voice: chunk upload failed', ['recordingId' => $this->recordingId, 'chunkIndex' => $index, 'attempt' => $n, 'error' => $e->getMessage()]);

        if ($permanent || $n === $this->warnAfterAttempts) {
            $this->fire(new UploadFailed($this->recordingId, $this->sessionId, $index, $n, $e->getMessage(), $permanent));
            $this->peer?->send(['type' => 'error', 'code' => $permanent ? 'upload_rejected' : 'upload_retrying', 'message' => $e->getMessage(), 'chunkIndex' => $index]);
        }
        if ($permanent) {
            $this->uploading = false;
            $this->persist(['status' => 'failed', 'error' => $e->getMessage()]);
            $this->end(['type' => 'done', 'failed' => true]);
            return;
        }
        $delay = $this->backoff[min($n - 1, count($this->backoff) - 1)];
        $this->loop->addTimer($delay, function () use ($item, $n) {
            if ($this->done) {
                $this->uploading = false;
                return;
            }
            $this->attempt($item, $n + 1);
        });
    }

    private function complete(): void
    {
        $this->persist(['status' => RecordingStore::FINISHED, 'finishedAt' => time()]);
        $this->log->info('emr-voice: recording finished', ['recordingId' => $this->recordingId, 'sessionId' => $this->sessionId, 'chunks' => $this->nextIndex, 'auto' => $this->autoFinish]);
        $this->fire(new RecordingFinished($this->recordingId, $this->sessionId, $this->nextIndex, $this->autoFinish));
        $this->end(['type' => 'done', 'chunks' => $this->nextIndex]);
    }

    private function end(array $message): void
    {
        $this->done = true;
        if ($this->peer) {
            $this->peer->send($message);
            $this->peer->close(1000, 'done');
            $this->peer = null;
        }
        if ($this->onDone) {
            ($this->onDone)($this);
        }
    }

    private function reportSilences(): void
    {
        foreach ($this->chunker->takeSilences() as $s) {
            $this->fire(new SilenceDetected($this->recordingId, $s['start'], $s['end'], $s['durationS']));
            $this->peer?->send(['type' => 'silence'] + $s);
        }
    }

    /** Bytes before this offset are uploaded or were silence: the browser may discard them. */
    private function ackable(): int
    {
        return $this->queue === [] ? $this->chunker->offset * 2 : $this->queue[0]['startByte'];
    }

    private function maybeAck(bool $force = false): void
    {
        $a = $this->ackable();
        if ($a > $this->ackedBytes && ($force || $a - $this->ackedBytes >= self::ACK_STEP_BYTES)) {
            $this->ackedBytes = $a;
            $this->persist(['ackedBytes' => $a]);
            $this->peer?->send(['type' => 'ack', 'offset' => $a]);
        }
    }
}
