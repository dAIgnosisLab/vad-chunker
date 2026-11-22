<?php

namespace EmrVoice\Tests\Feature;

use EmrVoice\Audio\Pcm;
use EmrVoice\Auth\TokenSigner;
use EmrVoice\Client\AsyncEmrApiClient;
use EmrVoice\EmrVoiceManager;
use EmrVoice\EmrVoiceServiceProvider;
use EmrVoice\Events\ChunkUploaded;
use EmrVoice\Events\RecordingCancelled;
use EmrVoice\Events\RecordingFinished;
use EmrVoice\Events\RecordingStarted;
use EmrVoice\Facades\EmrVoice;
use EmrVoice\Recording\RecordingFactory;
use EmrVoice\Recording\RecordingStore;
use EmrVoice\Server\WebSocketServer;
use EmrVoice\Tests\Support\FakeEmrApi;
use EmrVoice\Tests\Support\Model;
use EmrVoice\Tests\Support\WsClient;
use EmrVoice\Vad\LiveChunker;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;
use Psr\Log\NullLogger;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;

/** End to end: browser-like client → WebSocket server → VAD → fake EMR API, all in one event loop. */
final class LiveRecordingTest extends TestCase
{
    private const FRAME_BYTES = 3200; // 100 ms of PCM16 per WebSocket message, like the browser recorder

    private LoopInterface $loop;
    private FakeEmrApi $api;
    private WebSocketServer $server;
    private string $wsUrl;

    protected function getPackageProviders($app): array
    {
        return [EmrVoiceServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('emr-voice.auth.secret', 'test-secret');
        $app['config']->set('emr-voice.hospital_id', 'hosp-1');
        $app['config']->set('emr-voice.upload.backoff_s', [0.01]);
        $app['config']->set('emr-voice.poll_result.enabled', false);
        $app['config']->set('emr-voice.server.resume_grace_s', 0.3);
        $app['config']->set('emr-voice.server.port', 0);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([RecordingStarted::class, ChunkUploaded::class, RecordingFinished::class, RecordingCancelled::class]);
        $this->loop = new StreamSelectLoop();
        $this->api = new FakeEmrApi($this->loop);
        config(['emr-voice.api.base_url' => $this->api->baseUrl, 'emr-voice.api.key' => 'test-key']);
        $this->startServer();
    }

    private function startServer(): void
    {
        $c = config('emr-voice');
        $api = new AsyncEmrApiClient($this->loop, $c['api']['base_url'], $c['api']['key'], 10);
        $factory = new RecordingFactory(Model::get(), app(EmrVoiceManager::class)->vadConfig(), $api, app(RecordingStore::class),
            $this->loop, app('events'), new NullLogger(), $c['upload'], $c['hospital_id']);
        $this->server = new WebSocketServer($this->loop, ['host' => '127.0.0.1'] + $c['server'], app(TokenSigner::class), $factory, new NullLogger());
        $this->server->listen();
        $this->wsUrl = str_replace('tcp://', 'ws://', $this->server->address()) . '/vad';
    }

    // ---------------------------------------------------------------- scenarios

    public function test_uninterrupted_recording_matches_offline_chunking_exactly(): void
    {
        $pcm = $this->fixture('talk.wav');
        $client = $this->record('consult-1', $pcm);
        $this->runUntil(fn () => $client->ofType('done') !== [], 60);

        $sid = EmrVoice::recording('consult-1')['sessionId'];
        $uploaded = $this->api->objects[$sid];
        $this->assertSame($this->offlineChunks($pcm), array_map('sha1', $uploaded), 'uploaded chunks = offline LiveChunker output');
        $this->assertSame(count($uploaded) - 1, $this->api->sessions[$sid]['final']);
        $this->assertSame(['hospitalId' => 'hosp-1', 'doctorId' => 'dr-1', 'patientId' => 'pt-1'],
            array_intersect_key($this->api->sessions[$sid], array_flip(['hospitalId', 'doctorId', 'patientId'])));
        $this->assertSame('finished', EmrVoice::recording('consult-1')['status']);
        $this->assertSame(['chunks' => count($uploaded)], array_intersect_key($client->ofType('done')[0], ['chunks' => 0]));
        Event::assertDispatched(RecordingStarted::class, 1);
        Event::assertDispatched(ChunkUploaded::class, count($uploaded));
        Event::assertDispatched(RecordingFinished::class, fn (RecordingFinished $e) => $e->chunks === count($uploaded) && !$e->auto);
        $acks = array_column($client->ofType('ack'), 'offset');
        $this->assertSame($acks, array_values(array_unique($acks)), 'acks only move forward');
    }

    public function test_network_drop_resumes_from_last_ack_without_losing_audio(): void
    {
        $pcm = $this->fixture('gaps.wav');
        $sent = 0;
        $client = $this->record('consult-2', $pcm, killAfterChunks: 1, sent: $sent);
        $this->runUntil(fn () => $client->closed, 30);
        $lastAck = max(array_column($client->ofType('ack'), 'offset') ?: [0]);

        $client2 = $this->record('consult-2', $pcm);
        $this->runUntil(fn () => $client2->ofType('done') !== [], 60);

        $resume = $client2->ofType('ready')[0]['resumeOffset'];
        $this->assertGreaterThanOrEqual($lastAck, $resume, 'the server never asks for audio the client already dropped');
        $this->assertLessThanOrEqual($sent, $resume, 'the server never claims audio that was not sent');
        $this->assertGreaterThan(0, $lastAck);
        $this->assertChunksAreOrderedSlicesOf($pcm, 'consult-2');
    }

    public function test_server_restart_resumes_from_the_store(): void
    {
        $pcm = $this->fixture('talk.wav');
        $client = $this->record('consult-3', $pcm, stopServerAfterChunks: 1);
        $this->runUntil(fn () => $client->closed, 30);
        $old = $this->server;
        $this->runUntil(fn () => array_filter($old->recordings(), fn ($r) => $r->hasPendingUploads()) === [], 10);
        $stored = EmrVoice::recording('consult-3');

        $this->startServer();       // the process restarted: the new one only has the store

        $client2 = $this->record('consult-3', $pcm);
        $this->runUntil(fn () => $client2->ofType('done') !== [], 60);
        $this->assertSame($stored['ackedBytes'], $client2->ofType('ready')[0]['resumeOffset']);
        $this->assertGreaterThan(0, $stored['ackedBytes']);
        $this->assertChunksAreOrderedSlicesOf($pcm, 'consult-3');
    }

    public function test_failed_uploads_and_lost_responses_never_leave_index_gaps(): void
    {
        $this->api->failures = ['put:0' => 2, 'url:1' => 1];
        $this->api->lostResponses = ['url:2' => 1];
        $pcm = $this->fixture('talk.wav');
        $client = $this->record('consult-4', $pcm);
        $this->runUntil(fn () => $client->ofType('done') !== [], 60);

        $sid = EmrVoice::recording('consult-4')['sessionId'];
        $this->assertSame($this->offlineChunks($pcm), array_map('sha1', $this->api->objects[$sid]));
        $this->assertSame(count($this->offlineChunks($pcm)), $this->api->sessions[$sid]['count'], 'no index was skipped');
    }

    public function test_rejects_bad_and_expired_tokens(): void
    {
        foreach (['not-a-token', app(TokenSigner::class)->sign(['rid' => 'x', 'pid' => 'p', 'did' => 'd'], -10)] as $token) {
            $c = new WsClient($this->loop, $this->wsUrl . '?token=' . urlencode($token));
            $c->connect();
            $this->runUntil(fn () => $c->httpStatus !== null, 5);
            $this->assertSame(401, $c->httpStatus);
        }
        $this->assertSame([], $this->api->sessions);
    }

    public function test_cancel_uploads_no_final_chunk(): void
    {
        $pcm = $this->fixture('talk.wav');
        $client = $this->record('consult-6', $pcm, stop: false);
        $client->onMessage[] = function (array $m) use ($client) {
            if ($m['type'] === 'chunk') {
                $client->sendJson(['type' => 'cancel']);
            }
        };
        $this->runUntil(fn () => $client->ofType('done') !== [], 60);

        $sid = EmrVoice::recording('consult-6')['sessionId'];
        $this->assertTrue($client->ofType('done')[0]['cancelled']);
        $this->assertNull($this->api->sessions[$sid]['final']);
        $this->assertSame('cancelled', EmrVoice::recording('consult-6')['status']);
        Event::assertDispatched(RecordingCancelled::class);
    }

    public function test_browser_that_never_returns_is_finished_after_the_grace_period(): void
    {
        $pcm = $this->fixture('talk.wav');
        $client = $this->record('consult-7', $pcm, killAtBytes: 70 * 32000);
        $this->runUntil(fn () => $client->closed, 30);
        $this->runUntil(fn () => (EmrVoice::recording('consult-7')['status'] ?? null) === 'finished', 30);

        $sid = EmrVoice::recording('consult-7')['sessionId'];
        $this->assertSame(count($this->api->objects[$sid]) - 1, $this->api->sessions[$sid]['final']);
        Event::assertDispatched(RecordingFinished::class, fn (RecordingFinished $e) => $e->auto);
    }

    public function test_recording_without_speech_still_closes_the_session(): void
    {
        $client = $this->record('consult-8', str_repeat("\0", 20 * 32000));
        $this->runUntil(fn () => $client->ofType('done') !== [], 30);

        $sid = EmrVoice::recording('consult-8')['sessionId'];
        $this->assertSame([0], array_keys($this->api->objects[$sid]));
        $this->assertSame(0, $this->api->sessions[$sid]['final']);
        $this->assertSame(1600, Pcm::samples($this->api->objects[$sid][0]), 'a 0.1 s silent final chunk');
        $this->assertNotEmpty($client->ofType('silence'));
    }

    // ---------------------------------------------------------------- helpers

    /** Connects like the browser recorder: waits for ready, streams from resumeOffset in 100 ms frames, then stops. */
    /**
     * @param int|null $killAtBytes drop the connection once this much audio was sent
     * @param int|null $killAfterChunks drop the connection after this many chunks were reported (streams at ~100× real time)
     * @param int|null $stopServerAfterChunks stop the server (process restart) after this many chunks
     */
    private function record(string $recordingId, string $pcm, ?int $killAtBytes = null, bool $stop = true,
        ?int $killAfterChunks = null, ?int $stopServerAfterChunks = null, int &$sent = 0): WsClient
    {
        $token = EmrVoice::token($recordingId, 'pt-1', 'dr-1');
        $client = new WsClient($this->loop, $this->wsUrl . '?token=' . urlencode($token));
        $perTick = ($killAfterChunks ?? $stopServerAfterChunks) !== null ? 1 : 20;
        $client->onMessage[] = function (array $m) use ($client, $pcm, $killAtBytes, $stop, $killAfterChunks, $stopServerAfterChunks, $perTick, &$sent) {
            if ($m['type'] !== 'ready') {
                return;
            }
            $pos = $m['resumeOffset'];
            $server = $this->server;
            $timer = $this->loop->addPeriodicTimer(0.001, function () use (&$timer, &$pos, &$sent, $client, $pcm, $killAtBytes, $stop, $killAfterChunks, $stopServerAfterChunks, $perTick, $server) {
                for ($i = 0; $i < $perTick; $i++) {
                    $chunks = count($client->ofType('chunk'));
                    if (($killAtBytes !== null && $pos >= $killAtBytes) || ($killAfterChunks !== null && $chunks >= $killAfterChunks)) {
                        $this->loop->cancelTimer($timer);
                        $client->kill();
                        return;
                    }
                    if ($stopServerAfterChunks !== null && $chunks >= $stopServerAfterChunks) {
                        $this->loop->cancelTimer($timer);
                        $server->stop();
                        return;
                    }
                    if ($client->closed) {
                        $this->loop->cancelTimer($timer);
                        return;
                    }
                    if ($pos >= strlen($pcm)) {
                        $this->loop->cancelTimer($timer);
                        if ($stop) {
                            $client->sendJson(['type' => 'stop']);
                        }
                        return;
                    }
                    $client->sendAudio(substr($pcm, $pos, self::FRAME_BYTES));
                    $pos += self::FRAME_BYTES;
                    $sent = max($sent, $pos);
                }
            });
        };
        $client->connect();
        return $client;
    }

    private function runUntil(callable $done, float $timeout): void
    {
        $failed = false;
        $deadline = $this->loop->addTimer($timeout, function () use (&$failed) {
            $failed = true;
            $this->loop->stop();
        });
        $check = $this->loop->addPeriodicTimer(0.005, function () use ($done) {
            if ($done()) {
                $this->loop->stop();
            }
        });
        if (!$done()) {
            $this->loop->run();
        }
        $this->loop->cancelTimer($deadline);
        $this->loop->cancelTimer($check);
        $this->assertFalse($failed, "timed out after {$timeout}s");
    }

    private function fixture(string $name): string
    {
        $path = Model::fixture($name) ?? $this->markTestSkipped("fixture $name not built (run tests/fixtures/build.sh)");
        return Pcm::fromWav(file_get_contents($path));
    }

    /** @return list<string> sha1 of each chunk the chunker produces for this stream in FRAME_BYTES pieces */
    private function offlineChunks(string $pcm): array
    {
        $chunker = new LiveChunker(Model::get(), app(EmrVoiceManager::class)->vadConfig());
        $out = [];
        for ($p = 0; $p < strlen($pcm); $p += self::FRAME_BYTES) {
            foreach ($chunker->feed(substr($pcm, $p, self::FRAME_BYTES)) as $c) {
                $out[] = sha1($c['audio']);
            }
        }
        foreach ($chunker->finish() as $c) {
            $out[] = sha1($c['audio']);
        }
        return $out;
    }

    /** Indices 0..n-1 with the last one final, and every chunk an exact, non-overlapping slice of the stream, in order. */
    private function assertChunksAreOrderedSlicesOf(string $pcm, string $recordingId): void
    {
        $sid = EmrVoice::recording($recordingId)['sessionId'];
        $objects = $this->api->objects[$sid];
        $this->assertSame(range(0, count($objects) - 1), array_keys($objects));
        $this->assertSame(count($objects) - 1, $this->api->sessions[$sid]['final']);
        $pos = 0;
        $covered = 0;
        foreach ($objects as $i => $chunk) {
            $at = strpos($pcm, $chunk, $pos);
            $this->assertNotFalse($at, "chunk #$i is a slice of the original stream after the previous chunk");
            $this->assertSame(0, $at % 2, "chunk #$i is sample aligned");
            $pos = $at + strlen($chunk);
            $covered += strlen($chunk);
        }
        $expected = array_sum(array_map(fn ($c) => strlen($c), $this->offlineAudio($pcm)));
        $this->assertEqualsWithDelta($expected, $covered, 3 * 32000, 'same amount of speech audio as an uninterrupted run (±3 s)');
    }

    /** @return list<string> */
    private function offlineAudio(string $pcm): array
    {
        $chunker = new LiveChunker(Model::get(), app(EmrVoiceManager::class)->vadConfig());
        $out = [];
        for ($p = 0; $p < strlen($pcm); $p += self::FRAME_BYTES) {
            array_push($out, ...array_column($chunker->feed(substr($pcm, $p, self::FRAME_BYTES)), 'audio'));
        }
        array_push($out, ...array_column($chunker->finish(), 'audio'));
        return $out;
    }
}
