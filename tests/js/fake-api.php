<?php
// Standalone fake EMR API (same rules as tests/Support/FakeEmrApi.php) for testing `emr-voice:serve` itself.
// Prints its base URL, then serves until killed. GET /debug/state dumps sessions and uploaded chunks.
require __DIR__ . '/../../vendor/autoload.php';

use EmrVoice\Audio\Pcm;
use EmrVoice\Tests\Support\FakeEmrApi;
use React\EventLoop\Loop;

$api = new FakeEmrApi(Loop::get(), $argv[1] ?? 'test-key');
$api->onDebug = fn () => ['sessions' => $api->sessions, 'chunks' => array_map(fn ($o) => array_map(fn ($pcm) => ['samples' => Pcm::samples($pcm), 'b64' => base64_encode($pcm)], $o), $api->objects)];
echo $api->baseUrl . "\n";
Loop::run();
