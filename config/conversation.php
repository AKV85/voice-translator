<?php

return [
    'audio_enabled' => env('CONVERSATION_AUDIO_ENABLED', false),
    'enabled' => env('CONVERSATION_ENABLED', false),
    'async_worker_enabled' => env('CONVERSATION_ASYNC_WORKER_ENABLED', false),
    'async_worker_timeout_seconds' => 60,

    'ttl_seconds' => (int) env(
        'CONVERSATION_TTL_SECONDS',
        1800,
    ),
];
