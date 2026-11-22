# emr-voice

Live consultation recording for Laravel apps. The browser streams the microphone to your backend over a
WebSocket; this package detects speech pauses (Silero VAD), cuts the audio into 45–90 s chunks at natural
pauses, and uploads them to the dAIgnosisLab EMR pipeline, which returns a structured EMR.

```
Browser (mic)  ──PCM 16 kHz──▶  your Laravel backend: php artisan emr-voice:serve  ──WAV chunks──▶  EMR API ──▶ EMR
 emr-voice-recorder.js          (WebSocket + VAD + upload, in this package)                         (dAIgnosisLab)
```

- Cuts happen in pauses, never mid-word; silence is dropped; each chunk is 16 kHz mono 16-bit WAV.
- Nothing is lost on a network drop or a server restart: the browser keeps audio until the server
  confirms it, reconnects on its own and resends from the confirmed point.
- The chunking is a line-by-line port of the dAIgnosisLab live recording pipeline and is tested to cut at
  exactly the same places.

## Requirements

- PHP 8.1+ with `ext-ffi` (enabled for the CLI by default: `ffi.enable=preload`), `ext-pcntl` recommended
- Laravel 10, 11 or 12
- A cache store shared by your servers (Redis recommended) and a queue worker (for EMR result events)
- Linux or macOS, x64 or arm64
- Your API key for the EMR API (from dAIgnosisLab)

## Install

```bash
composer config repositories.emr-voice vcs https://github.com/dAIgnosisLab/emr-voice
composer require daignosislab/emr-voice

php artisan emr-voice:install-runtime     # downloads ONNX Runtime 1.23.0 (verified checksum) to storage/emr-voice/
php artisan vendor:publish --tag=emr-voice-config
php artisan vendor:publish --tag=emr-voice-assets   # → public/vendor/emr-voice/*.js
```

`.env`:

```env
EMR_VOICE_API_KEY=your-key
EMR_VOICE_HOSPITAL_ID=your-hospital-id
EMR_VOICE_WS_PORT=8090
EMR_VOICE_ALLOWED_ORIGINS=https://your-app.example
```

Check everything:

```bash
php artisan emr-voice:doctor
```

## Use

### 1. A route that issues a recording token

Your app decides who is recording what. The token (valid 5 min) binds one connection to one recording;
the browser asks for a fresh one when it reconnects.

```php
use EmrVoice\Facades\EmrVoice;

Route::post('/consultations/{consultation}/recording-token', function (Consultation $consultation) {
    return ['token' => EmrVoice::token(
        recordingId: (string) $consultation->id,     // your id; reconnects reuse it
        patientId: (string) $consultation->patient_id,
        doctorId: (string) auth()->user()->doctor_id,
    )];
})->middleware('auth');
```

### 2. The browser

```html
<script src="/vendor/emr-voice/emr-voice-recorder.js"></script>
<script>
  let recorder;
  startButton.onclick = async () => {
    recorder = await EmrVoiceRecorder.start({
      url: 'wss://your-app.example/vad',
      getToken: () => fetch(`/consultations/${id}/recording-token`, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf } })
        .then((r) => r.json()).then((r) => r.token),
      onStateChange: (s) => (status.textContent = s), // connecting | recording | reconnecting | finishing | done | cancelled | failed
      onLevel: (rms) => (meter.value = rms),          // microphone level, for a VU meter
      onChunk: (c) => console.log('sent chunk', c.index),
      onError: (e) => console.warn(e.code, e.message),
    });
  };
  stopButton.onclick = async () => { await recorder.stop(); status.textContent = 'Generating EMR…'; };
  discardButton.onclick = () => recorder.cancel();
</script>
```

The page must be served over HTTPS (browsers only allow the microphone on secure origins).

### 3. The EMR

When the final chunk is uploaded, a queued job polls for the result and fires an event:

```php
use EmrVoice\Events\EmrReady;
use EmrVoice\Events\EmrFailed;

Event::listen(function (EmrReady $e) {
    Consultation::find($e->recordingId)->update(['emr' => $e->emr]);
});
Event::listen(function (EmrFailed $e) {
    // $e->status: no_speech | cancelled | failed | timeout
});
```

Or ask directly: `EmrVoice::result($recordingId)` → `['status' => 'completed', 'emr' => [...]]`, and
`EmrVoice::recording($recordingId)` for the recording's state.

Other events: `RecordingStarted`, `ChunkUploaded`, `SilenceDetected`, `UploadFailed`, `RecordingFinished`,
`RecordingCancelled`. All except `EmrReady`/`EmrFailed` are fired inside the WebSocket server process — make
listeners that do real work `ShouldQueue` so they don't hold up audio processing.

## Run in production

Supervisor:

```ini
[program:emr-voice]
command=php /var/www/app/artisan emr-voice:serve
user=www-data
autostart=true
autorestart=true
stopsignal=TERM
stopwaitsecs=15

[program:emr-voice-queue]
command=php /var/www/app/artisan queue:work
user=www-data
autostart=true
autorestart=true
```

nginx (inside your HTTPS server block):

```nginx
location /vad {
    proxy_pass http://127.0.0.1:8090;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 3600s;
    proxy_send_timeout 3600s;
}
```

- One `emr-voice:serve` process handles dozens of simultaneous recordings (VAD costs about 3 ms per
  second of audio). Restart it freely (deploys): browsers reconnect and resume.
- Several servers: use a shared cache store (`EMR_VOICE_CACHE_STORE=redis`) so a reconnect can resume on any
  server; sticky routing (`ip_hash`) keeps a recording on one process, which is preferable.
- If the browser disappears without pressing stop, the recording is finished automatically after
  `server.resume_grace_s` (60 s) so the EMR is still generated (`on_disconnect` can be set to `cancel`).

## Configuration

See `config/emr-voice.php`. The `vad` section holds the tested chunking rules of the pipeline — leave them as
they are unless dAIgnosisLab asks otherwise.

## WebSocket protocol (for custom clients)

`wss://host/vad?token=…` — after `{"type":"ready","resumeOffset":n}`, send binary frames of PCM16LE,
16 kHz, mono, starting at byte `n` of your stream (0 for a new recording), in order, any size.

| From | Message | Meaning |
|---|---|---|
| client | binary | audio |
| client | `{"type":"stop"}` | end of recording — generate the EMR |
| client | `{"type":"cancel"}` | discard — no EMR |
| server | `{"type":"ready","resumeOffset":n}` | send audio from byte `n` |
| server | `{"type":"ack","offset":n}` | bytes before `n` are safe — drop them from your buffer |
| server | `{"type":"chunk","index",…}` / `{"type":"silence",…}` | progress |
| server | `{"type":"finishing"}` then `{"type":"done","chunks":n}` | finished (or `cancelled` / `failed`) |
| server | `{"type":"error","code","message"}` | `upload_retrying`, `upload_rejected`, `idle`, `not_ready`, … |

Keep every byte you sent until it is acked; after a reconnect, resend from `resumeOffset`.

## Development

```bash
docker build -t emr-voice-dev docker/
docker run --rm -v "$PWD":/pkg emr-voice-dev composer install
docker run --rm -v "$PWD":/pkg emr-voice-dev tests/fixtures/build.sh      # decode the test recordings (ffmpeg)
docker run --rm -v "$PWD":/pkg emr-voice-dev vendor/bin/testbench emr-voice:install-runtime
docker run --rm -v "$PWD":/pkg emr-voice-dev vendor/bin/phpunit
node tests/js/worklet.test.mjs && node tests/js/stream.test.mjs && node tests/js/serve-command.test.mjs
```

`tests/reference/` holds the cut points produced by the production `vad.js` for the fixtures
(`generate.mjs`); the parity tests require the PHP port to match them exactly. Put your own recordings in
`tests/fixtures/private/` (never committed) to compare on real consultations.
