<?php

use App\Contracts\SpeechToTextProvider;
use App\Services\Speech\DeepgramFluxSpeechToTextProvider;
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
            'chunk_duration_ms' => 80,

            'providers' => [
                'deepgram-flux' => [
                    'contract' => DeepgramFluxSpeechToTextProvider::class,

                    'provider' => 'deepgram',

                    'model' => 'flux-general-multi',

                    'config' => [
                        'services.deepgram.flux_endpoint' => 'wss://api.deepgram.com/v2/listen',

                        'services.deepgram.flux_model' => 'flux-general-multi',
                    ],
                ],

                'deepgram-flux-keyterms' => [
                    'contract' => DeepgramFluxSpeechToTextProvider::class,
                    'provider' => 'deepgram',
                    'model' => 'flux-general-multi',
                    'config' => [
                        'services.deepgram.flux_endpoint' => 'wss://api.deepgram.com/v2/listen',

                        'services.deepgram.flux_model' => 'flux-general-multi',

                        'services.deepgram.flux_keyterms' => [
                            'Klaipėda',
                            'Klaipeda',
                            'Клайпеда',
                            'ZZ 546',
                            'Persik',
                            'trailer',
                            'truck',
                            'unloading',
                            'leave',
                        ],
                    ],
                ],
            ],
        ],
    ],
];
