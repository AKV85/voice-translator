<?php

use App\Contracts\SpeechToTextProvider;

return [
    'speech' => [
        'dataset_path' => base_path('docs/benchmarks/speech/phrases.json'),

        'results_path' => base_path('docs/benchmarks/speech/results'),

        'providers' => [
            'google-short' => [
                'contract' => SpeechToTextProvider::class,
                'provider' => 'google',
                'model' => 'short',
                'config' => [
                    'services.google.speech.model' => 'short',
                ],
            ],
        ],
    ],
];
