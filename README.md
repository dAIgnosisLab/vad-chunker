# vad-chunker

Audio in, speech chunks out. Feed a live (or recorded) 16 kHz mono audio stream; get back WAV chunks of
45–90 s cut inside natural speech pauses, with silence dropped. Voice activity detection is Silero VAD v5
(ONNX), run in-process through PHP FFI — no Python, no external service.

The chunking rules are a line-by-line port of the dAIgnosisLab live recording pipeline and are tested to cut
at exactly the same places (`tests/Parity`).

## Requirements

- PHP 8.1+ with `ext-ffi` (`ffi.enable=preload` — the default — is enough for CLI/queue/WebSocket processes;
  set `ffi.enable=true` to use it inside PHP-FPM requests)
- Linux or macOS, x64 or arm64
- Laravel 10–12 optional (facade, config, artisan commands)

## Install

```bash
composer config repositories.vad-chunker vcs https://github.com/dAIgnosisLab/vad-chunker
composer require daignosislab/vad-chunker

php artisan vad-chunker:install-runtime   # ONNX Runtime 1.23.0, checksum-verified, into storage/vad-chunker/
php artisan vad-chunker:doctor            # checks FFI, runtime, model, runs one inference
```

Without Laravel: `vendor/bin/vad-chunker install-runtime [path]`.

## Use

Input is raw PCM16LE, 16 kHz, mono (or 16 kHz mono 16-bit WAV via `feedWav()` / `chunkWav()`).

### A live stream

One chunker per stream. Feed audio as it arrives, any size; chunks come out when they are final.

```php
use VadChunker\Facades\VadChunker;

$chunker = VadChunker::make();

// for each piece of audio received (e.g. each WebSocket message):
foreach ($chunker->feed($pcm) as $chunk) {
    handle($chunk);
}

// end of the stream:
foreach ($chunker->finish() as $chunk) {
    handle($chunk);
}

function handle(\VadChunker\Chunk $chunk): void
{
    $chunk->wav();          // WAV file contents (16 kHz mono 16-bit)
    $chunk->pcm;            // raw PCM16LE
    $chunk->index;          // 0, 1, 2, …
    $chunk->start;          // stream seconds this chunk covers
    $chunk->end;
    $chunk->reason;         // pause | long-pause | quietest-point | end
    $chunk->speechRatio;    // share of speech frames
    $chunk->seconds();      // length of the audio
}
```

A chunk is released once 2 s of audio after its cut have arrived, so most `feed()` calls return nothing.

### Across requests

If each piece of the stream arrives in a separate HTTP request, save the state between them:

```php
$chunker = $snapshot ? VadChunker::restore($snapshot) : VadChunker::make();
$chunks = $chunker->feed($request->getContent());
$snapshot = $chunker->snapshot();   // store it (cache/redis/file) for the next request — up to ~3 MB
```

Restoring between every piece gives exactly the same chunks as one continuous stream (tested). Requests for
one stream must be processed in order.

### A whole recording

```php
$chunks = VadChunker::chunkWav(file_get_contents('consultation.wav'));
```

CLI: `vendor/bin/vad-chunker chunk consultation.wav out/` writes `out/chunk-000.wav …` and prints the metadata.

### Other

- `$chunker->silences()` — silent spans of 5 s or more dropped since the last call (`[start, end, durationS]`)
- `$chunker->lastSpeechSeconds()` — when someone last spoke (for "is anyone talking?" checks)
- `VadChunker::make(['min_s' => 20, 'max_s' => 40])` — override rules per stream

Without Laravel:

```php
use VadChunker\Vad\SileroModel;
use VadChunker\VadChunker;

$model = SileroModel::shared('vendor/daignosislab/vad-chunker/resources/models/silero_vad.onnx', '/path/to/libonnxruntime.so.1');
$chunker = new VadChunker($model);
```

The model is loaded once per process and shared by all chunkers; each `feed()` costs about 3 ms per second of
audio.

## Sending chunks to the dAIgnosisLab EMR API

Each chunk goes to the Midas API as one upload: ask for an upload URL with the chunk's index, then PUT the
WAV to it. The last chunk carries `isFinal: true`, which starts EMR generation. Full API reference:
"Midas API: Developer Integration Guide".

```php
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use VadChunker\Audio\Pcm;
use VadChunker\Facades\VadChunker;

$api = Http::baseUrl('https://midas-api.ailab.com.np/api/v1')
    ->withHeaders(['X-API-Key' => config('services.midas.key')])
    ->acceptJson();

// once per recording
$sessionId = $api->post('/sessions', [
    'hospitalId' => $hospitalId, 'doctorId' => $doctorId, 'patientId' => $patientId,
])->throw()->json('sessionId');
$chunker = VadChunker::make();
$next = 0;   // next chunk index: keep it with the recording (with the snapshot when you use one)

function uploadChunk(PendingRequest $api, string $sessionId, int $index, string $wav, bool $isFinal): void
{
    // Safe to retry: the same chunkIndex always maps to the same chunk, so a retry never leaves a gap.
    retry(5, function () use ($api, $sessionId, $index, $wav, $isFinal) {
        $url = $api->post("/sessions/{$sessionId}/chunks/url", ['chunkIndex' => $index, 'isFinal' => $isFinal])
            ->throw()->json('uploadUrl');
        Http::withBody($wav, 'audio/wav')->put($url)->throw();
    }, fn (int $attempt) => 500 * $attempt);
}

// while recording: upload each chunk as soon as it is ready
foreach ($chunker->feed($pcm) as $chunk) {
    uploadChunk($api, $sessionId, $next++, $chunk->wav(), false);
}

// when the recording stops: the remaining chunks, the last one final
$last = $chunker->finish();
if ($last === []) {
    // nothing left but silence: a 0.1 s silent WAV closes the session
    uploadChunk($api, $sessionId, $next++, Pcm::toWav(str_repeat("\0", 3200)), true);
}
foreach ($last as $i => $chunk) {
    uploadChunk($api, $sessionId, $next++, $chunk->wav(), $i === array_key_last($last));
}

// later: poll until status is "completed"
$result = $api->get("/sessions/{$sessionId}/result")->throw()->json();   // ['status' => …, 'emr' => …]
```

Upload from a queued job if your request handling must stay fast; keep the uploads of one recording in
order (one worker per recording, or `WithoutOverlapping` keyed by the session).

## Rules (config/vad-chunker.php)

| | default | |
|---|---|---|
| `min_s` | 45 | never cut before |
| `target_s` | 60 | from here, cut at the first pause ≥ `min_pause_s` (0.3 s) |
| `max_s` | 90 | no pause by then: cut at the quietest 0.3 s |
| `long_pause_s` | 0.8 | between `min_s` and `target_s`, cut only at a pause this long |
| `threshold` / `neg_threshold` | 0.5 / 0.35 | speech on / off probabilities |
| `settle_s` | 2 | a cut is final once this much later audio arrived |
| `drop_silence`, `keep_s` | true, 1 | drop audio while nobody speaks, keep 1 s before speech |
| `trim`, `trim_min_s`, `trim_pad_s` | true, 1, 0.3 | trim leading/trailing silence > 1 s inside a chunk |

Cuts always fall inside a pause (up to 1 s into it), so no word is split.

## Development

```bash
docker build -t vad-chunker-dev docker/
docker run --rm -v "$PWD":/pkg vad-chunker-dev composer install
docker run --rm -v "$PWD":/pkg vad-chunker-dev tests/fixtures/build.sh
docker run --rm -v "$PWD":/pkg vad-chunker-dev vendor/bin/testbench vad-chunker:install-runtime
docker run --rm -v "$PWD":/pkg vad-chunker-dev vendor/bin/phpunit
```

`tests/reference/*.json` are the cuts the production `vad.js` makes on the fixtures (`tests/reference/generate.mjs`);
`tests/Parity` requires the PHP port to match them exactly. Recordings in `tests/fixtures/private/` (never
committed) are included when present.
