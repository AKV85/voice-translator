<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'speech' => [
            'project_id' => env('GOOGLE_CLOUD_PROJECT'),
            'location' => env('GOOGLE_CLOUD_SPEECH_LOCATION', 'global'),
            'model' => env('GOOGLE_CLOUD_SPEECH_MODEL', 'short'),
            'credentials_path' => env('GOOGLE_APPLICATION_CREDENTIALS'),
            'api_endpoint' => env('GOOGLE_CLOUD_SPEECH_API_ENDPOINT'),
        ],

        'translation' => [
            'project_id' => env('GOOGLE_CLOUD_PROJECT'),

            'location' => env(
                'GOOGLE_CLOUD_TRANSLATION_LOCATION',
                'global',
            ),

            'model' => env(
                'GOOGLE_CLOUD_TRANSLATION_MODEL',
                'general/nmt',
            ),

            'credentials_path' => env(
                'GOOGLE_APPLICATION_CREDENTIALS',
            ),
        ],
    ],

    'deepl' => [
        'auth_key' => env('DEEPL_AUTH_KEY'),

        'model_type' => env(
            'DEEPL_MODEL_TYPE',
            'latency_optimized',
        ),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),

        'translation' => [
            'model' => env(
                'OPENAI_TRANSLATION_MODEL',
                'gpt-5.4-mini-2026-03-17',
            ),

            'endpoint' => env(
                'OPENAI_TRANSLATION_ENDPOINT',
                'https://api.openai.com/v1/responses',
            ),

            'service_tier' => env(
                'OPENAI_TRANSLATION_SERVICE_TIER',
                'default',
            ),
        ],

        'realtime_translation' => [
            'model' => env(
                'OPENAI_REALTIME_TRANSLATION_MODEL',
                'gpt-realtime-translate',
            ),

            'endpoint' => env(
                'OPENAI_REALTIME_TRANSLATION_ENDPOINT',
                'wss://api.openai.com/v1/realtime/translations',
            ),
        ],

        'text_to_speech' => [
            'model' => env(
                'OPENAI_TTS_MODEL',
                'gpt-4o-mini-tts-2025-12-15',
            ),

            'endpoint' => env(
                'OPENAI_TTS_ENDPOINT',
                'https://api.openai.com/v1/audio/speech',
            ),

            'voice' => env(
                'OPENAI_TTS_VOICE',
                'marin',
            ),
        ],
    ],

    'deepgram' => [
        'api_key' => env('DEEPGRAM_API_KEY'),

        'endpoint' => env(
            'DEEPGRAM_API_ENDPOINT',
            'https://api.deepgram.com/v1/listen',
        ),

        'model' => env(
            'DEEPGRAM_MODEL',
            'nova-3',
        ),
    ],
];
