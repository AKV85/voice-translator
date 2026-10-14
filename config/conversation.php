<?php

return [
    'enabled' => env('CONVERSATION_ENABLED', false),

    'ttl_seconds' => (int) env(
        'CONVERSATION_TTL_SECONDS',
        1800,
    ),
];
