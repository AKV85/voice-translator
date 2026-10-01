<?php

return [
    'enabled' => env(
        'PUBLIC_VOICE_DEMO_ENABLED',
        false,
    ),

    'websocket_url' => env(
        'PUBLIC_VOICE_DEMO_WS_URL',
    ),

    'token_ttl_seconds' => (int) env(
        'PUBLIC_VOICE_DEMO_TOKEN_TTL_SECONDS',
        60,
    ),

    'max_audio_seconds' => (int) env(
        'PUBLIC_VOICE_DEMO_MAX_AUDIO_SECONDS',
        15,
    ),

    'max_audio_bytes' => (int) env(
        'PUBLIC_VOICE_DEMO_MAX_AUDIO_BYTES',
        2_000_000,
    ),

    'hourly_limit' => (int) env(
        'PUBLIC_VOICE_DEMO_HOURLY_LIMIT',
        5,
    ),

    'daily_limit' => (int) env(
        'PUBLIC_VOICE_DEMO_DAILY_LIMIT',
        15,
    ),

    'ip_daily_limit' => (int) env(
        'PUBLIC_VOICE_DEMO_IP_DAILY_LIMIT',
        30,
    ),

    'global_daily_limit' => (int) env(
        'PUBLIC_VOICE_DEMO_GLOBAL_DAILY_LIMIT',
        200,
    ),

    'profiles' => [
        'ru' => env(
            'PUBLIC_VOICE_DEMO_RU_PROFILE',
            'chirp3-streaming-standard-deepl-openai',
        ),

        'en' => env(
            'PUBLIC_VOICE_DEMO_EN_PROFILE',
            'chirp3-streaming-short-deepl-openai',
        ),
    ],
];
