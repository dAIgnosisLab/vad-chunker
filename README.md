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
