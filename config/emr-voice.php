<?php

return [

    // Our EMR API (sessions, chunk upload URLs, results). Default is the dAIgnosisLab endpoint.
    'api' => [
        'base_url' => env('EMR_VOICE_API_URL', 'https://midas-api.ailab.com.np'),
        'key' => env('EMR_VOICE_API_KEY'),
        'timeout' => (float) env('EMR_VOICE_API_TIMEOUT', 20),
    ],

    // Used when a token does not name a hospital.
    'hospital_id' => env('EMR_VOICE_HOSPITAL_ID'),

    // WebSocket server started by `php artisan emr-voice:serve` (put it behind nginx/TLS).
    'server' => [
        'host' => env('EMR_VOICE_WS_HOST', '127.0.0.1'),
        'port' => (int) env('EMR_VOICE_WS_PORT', 8090),
        'path' => env('EMR_VOICE_WS_PATH', '/vad'),
        'allowed_origins' => array_filter(explode(',', (string) env('EMR_VOICE_ALLOWED_ORIGINS', ''))), // empty = any
        'max_connections' => 200,
        'max_frame_bytes' => 256 * 1024,
        'idle_timeout_s' => 120,   // no audio this long while connected: close
        'resume_grace_s' => 60,    // after a disconnect, wait this long for the browser to reconnect
        'on_disconnect' => 'finish', // after the grace period: 'finish' (generate the EMR from what was recorded) or 'cancel'
    ],

    // Browser tokens (EmrVoice::token()). Signed with HMAC-SHA256.
    'auth' => [
        'secret' => env('EMR_VOICE_SECRET', env('APP_KEY')),
        'token_ttl_s' => 300,
    ],

    // Chunking rules — the tested defaults of our pipeline. Change only together with us.
    'vad' => [
        'min_s' => 45, 'target_s' => 60, 'max_s' => 90,
        'min_pause_s' => 0.3, 'long_pause_s' => 0.8,
        'threshold' => 0.5, 'neg_threshold' => 0.35,
        'settle_s' => 2.0,
        'drop_silence' => true, 'keep_s' => 1.0, 'silence_report_s' => 5,
        'trim' => true, 'trim_min_s' => 1.0, 'trim_pad_s' => 0.3,
    ],

    'upload' => [
        'backoff_s' => [1, 2, 5, 10, 30], // retried until it succeeds; the last value repeats
        'warn_after_attempts' => 5,       // fire UploadFailed and tell the browser after this many failures
    ],

    // Recording state kept for resume and EmrVoice::result(). Must be shared (redis) with several servers.
    'store' => [
        'cache_store' => env('EMR_VOICE_CACHE_STORE'), // null = default cache store
        'ttl_s' => 86400,
    ],

    // After a recording finishes, poll for the EMR and fire EmrReady / EmrFailed (needs a queue worker).
    'poll_result' => [
        'enabled' => true,
        'connection' => env('EMR_VOICE_QUEUE_CONNECTION'),
        'queue' => env('EMR_VOICE_QUEUE', 'default'),
        'interval_s' => 10,
        'timeout_s' => 900,
    ],

    'model_path' => null,   // null = bundled resources/models/silero_vad.onnx
    'runtime_path' => env('EMR_VOICE_RUNTIME_PATH'), // null = storage/emr-voice/libonnxruntime.so.1 (php artisan emr-voice:install-runtime)

    'log_channel' => env('EMR_VOICE_LOG_CHANNEL'),
];
