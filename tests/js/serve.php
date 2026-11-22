<?php
// Runs the emr-voice WebSocket server against the in-process fake EMR API, for tests/js/stream.test.mjs.
// Prints one JSON line {wsUrl, apiUrl, tokens} and serves until killed. GET {apiUrl}/debug/state dumps uploads.

require __DIR__ . '/../../vendor/autoload.php';

use EmrVoice\Audio\Pcm;
use EmrVoice\Auth\TokenSigner;
use EmrVoice\Client\AsyncEmrApiClient;
use EmrVoice\Recording\RecordingFactory;
use EmrVoice\Recording\RecordingStore;
use EmrVoice\Server\WebSocketServer;
use EmrVoice\Tests\Support\FakeEmrApi;
use EmrVoice\Tests\Support\Model;
use EmrVoice\Vad\VadConfig;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Events\Dispatcher;
use Psr\Log\AbstractLogger;
use React\EventLoop\Loop;

$port = (int) ($argv[1] ?? 8099);
$loop = Loop::get();
$api = new FakeEmrApi($loop);
$api->onDebug = fn () => ['sessions' => $api->sessions, 'chunks' => array_map(fn ($o) => array_map(fn ($pcm) => ['samples' => Pcm::samples($pcm), 'b64' => base64_encode($pcm)], $o), $api->objects)];

$log = new class extends AbstractLogger {
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        fwrite(STDERR, "[$level] $message " . json_encode($context) . "\n");
    }
};
$tokens = new TokenSigner('js-test');
$factory = new RecordingFactory(Model::get(), new VadConfig(), new AsyncEmrApiClient($loop, $api->baseUrl, $api->apiKey, 10),
    new RecordingStore(new Repository(new ArrayStore()), 3600), $loop, new Dispatcher(), $log, ['backoff_s' => [0.05], 'warn_after_attempts' => 5], 'hosp-js');
$server = new WebSocketServer($loop, ['host' => '0.0.0.0', 'port' => $port, 'path' => '/vad', 'allowed_origins' => [], 'max_connections' => 50,
    'max_frame_bytes' => 262144, 'idle_timeout_s' => 120, 'resume_grace_s' => 30, 'on_disconnect' => 'finish'], $tokens, $factory, $log);
$server->listen();

echo json_encode([
    'wsUrl' => "ws://127.0.0.1:$port/vad",
    'apiUrl' => $api->baseUrl,
    'tokens' => array_map(fn ($rid) => $tokens->sign(['rid' => $rid, 'pid' => 'pt-js', 'did' => 'dr-js'], 3600), ['js-1', 'js-2', 'js-3']),
]) . "\n";
$loop->run();
