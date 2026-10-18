<?php

return [
    'latency_debug_enabled' => env('CONVERSATION_LATENCY_DEBUG_ENABLED', false),
    'audio_enabled' => env('CONVERSATION_AUDIO_ENABLED', false),
    'enabled' => env('CONVERSATION_ENABLED', false),
    'async_worker_enabled' => env('CONVERSATION_ASYNC_WORKER_ENABLED', false),
    'async_worker_timeout_seconds' => 60,
    'incremental_stt_enabled' => env('CONVERSATION_INCREMENTAL_STT_ENABLED', false),
    'stt_bridge_host' => env('CONVERSATION_STT_BRIDGE_HOST', 'host.docker.internal'),
    'stt_bridge_port' => (int) env('CONVERSATION_STT_BRIDGE_PORT', 8767),

    'ttl_seconds' => (int) env(
        'CONVERSATION_TTL_SECONDS',
        1800,
    ),
];
