<?php

use App\Contracts\SpeechToTextProvider;
use App\Services\Speech\DeepgramSpeechToTextProvider;

return [
    'speech' => [
        'dataset_path' => base_path(
            'docs/benchmarks/speech/phrases.json'
        ),

        'results_path' => base_path(
            'docs/benchmarks/speech/results'
        ),

        'providers' => [
            'google-short' => [
                'contract' => SpeechToTextProvider::class,
                'provider' => 'google',
                'model' => 'short',
                'config' => [
                    'services.google.speech.model' => 'short',
                    'services.google.speech.location' => 'global',
                    'services.google.speech.api_endpoint' => null,
                ],
            ],

            'google-chirp-3' => [
                'contract' => SpeechToTextProvider::class,
                'provider' => 'google',
                'model' => 'chirp_3',
                'config' => [
                    'services.google.speech.model' => 'chirp_3',
                    'services.google.speech.location' => 'eu',
                    'services.google.speech.api_endpoint' => 'eu-speech.googleapis.com',
                ],
            ],

            'deepgram-nova-3' => [
                'contract' => DeepgramSpeechToTextProvider::class,
                'provider' => 'deepgram',
                'model' => 'nova-3',
                'config' => [
                    'services.deepgram.model' => 'nova-3',
                ],
            ],
        ],

        'streaming' => [
            'chunk_size_bytes' => 4096,
            'chunk_interval_ms' => 0,

            'providers' => [
                //
            ],
        ],
    ],
];
