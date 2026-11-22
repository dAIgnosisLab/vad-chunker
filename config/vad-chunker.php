<?php

return [

    // Chunking rules. The defaults are the tested values of the dAIgnosisLab live pipeline.
    'vad' => [
        'min_s' => 45,            // never cut before this
        'target_s' => 60,         // from here, cut at the first pause >= min_pause_s
        'max_s' => 90,            // no pause by here: cut at the quietest point
        'min_pause_s' => 0.3,
        'long_pause_s' => 0.8,    // between min_s and target_s, cut only at a pause this long
        'threshold' => 0.5,       // speech starts at this probability…
        'neg_threshold' => 0.35,  // …and ends below this
        'settle_s' => 2.0,        // a cut is final once this much later audio has arrived
        'drop_silence' => true,   // discard audio while nobody speaks (keeping keep_s before speech)
        'keep_s' => 1.0,
        'silence_report_s' => 5,  // report silent spans at least this long (silences())
        'trim' => true,           // trim leading/trailing silence longer than trim_min_s inside a chunk
        'trim_min_s' => 1.0,
        'trim_pad_s' => 0.3,
    ],

    'model_path' => null,     // null = bundled resources/models/silero_vad.onnx
    'runtime_path' => env('VAD_CHUNKER_RUNTIME_PATH'), // null = storage/vad-chunker/<lib> (php artisan vad-chunker:install-runtime)
];
