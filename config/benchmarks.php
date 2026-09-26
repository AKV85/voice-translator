<?php

use App\Contracts\SpeechToTextProvider;
use App\Contracts\TranslationProvider;
use App\Services\Speech\BatchSpeechToTextStreamingAdapter;
use App\Services\Speech\DeepgramFluxSpeechToTextProvider;
use App\Services\Speech\DeepgramSpeechToTextProvider;
use App\Services\Speech\OpenAIStreamingTextToSpeechProvider;
use App\Services\Translation\DeepLTranslationProvider;
use App\Services\Translation\OpenAIRealtimeTranslationProvider;
use App\Services\Translation\OpenAITranslationProvider;

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

    'translation' => [
        'dataset_path' => base_path(
            'docs/benchmarks/translation/phrases.json'
        ),

        'results_path' => base_path(
            'docs/benchmarks/translation/results'
        ),

        'providers' => [
            'google-nmt' => [
                'contract' => TranslationProvider::class,

                'provider' => 'google',

                'model' => 'general/nmt',

                'config' => [
                    'services.google.translation.location' => 'global',

                    'services.google.translation.model' => 'general/nmt',
                ],
            ],

            'deepl-latency' => [
                'contract' => DeepLTranslationProvider::class,

                'provider' => 'deepl',

                'model' => 'latency_optimized',

                'config' => [
                    'services.deepl.model_type' => 'latency_optimized',
                ],
            ],

            'openai-gpt54-mini' => [
                'contract' => OpenAITranslationProvider::class,

                'provider' => 'openai',

                'model' => 'gpt-5.4-mini-2026-03-17',

                'config' => [
                    'services.openai.translation.model' => 'gpt-5.4-mini-2026-03-17',

                    'services.openai.translation.service_tier' => 'default',
                ],
            ],

            'openai-gpt55' => [
                'contract' => OpenAITranslationProvider::class,

                'provider' => 'openai',

                'model' => 'gpt-5.5-2026-04-23',

                'config' => [
                    'services.openai.translation.model' => 'gpt-5.5-2026-04-23',

                    'services.openai.translation.service_tier' => 'default',
                ],
            ],
        ],

        'realtime' => [
            'chunk_duration_ms' => 100,

            'runs_per_fixture' => 3,

            'providers' => [
                'openai-realtime' => [
                    'contract' => OpenAIRealtimeTranslationProvider::class,

                    'provider' => 'openai',

                    'model' => 'gpt-realtime-translate',

                    'config' => [
                        'services.openai.realtime_translation.endpoint' => 'wss://api.openai.com/v1/realtime/translations',

                        'services.openai.realtime_translation.model' => 'gpt-realtime-translate',
                    ],
                ],
            ],
        ],

        'pipeline' => [
            'chunk_duration_ms' => 80,

            'runs_per_fixture' => 3,

            'providers' => [
                'flux-openai-openai' => [
                    'speech_to_text' => [
                        'contract' => DeepgramFluxSpeechToTextProvider::class,

                        'provider' => 'deepgram',

                        'model' => 'flux-general-multi',

                        'config' => [
                            'services.deepgram.flux_endpoint' => 'wss://api.deepgram.com/v2/listen',

                            'services.deepgram.flux_model' => 'flux-general-multi',

                            'services.deepgram.flux_keyterms' => [],
                        ],
                    ],

                    'translation' => [
                        'contract' => OpenAITranslationProvider::class,

                        'provider' => 'openai',

                        'model' => 'gpt-5.4-mini-2026-03-17',

                        'config' => [
                            'services.openai.translation.model' => 'gpt-5.4-mini-2026-03-17',

                            'services.openai.translation.service_tier' => 'default',
                        ],
                    ],

                    'text_to_speech' => [
                        'contract' => OpenAIStreamingTextToSpeechProvider::class,

                        'provider' => 'openai',

                        'model' => 'gpt-4o-mini-tts-2025-12-15',

                        'config' => [
                            'services.openai.text_to_speech.model' => 'gpt-4o-mini-tts-2025-12-15',

                            'services.openai.text_to_speech.endpoint' => 'https://api.openai.com/v1/audio/speech',

                            'services.openai.text_to_speech.voice' => 'marin',
                        ],
                    ],
                ],

                'flux-deepl-openai' => [
                    'speech_to_text' => [
                        'contract' => DeepgramFluxSpeechToTextProvider::class,

                        'provider' => 'deepgram',

                        'model' => 'flux-general-multi',

                        'config' => [
                            'services.deepgram.flux_endpoint' => 'wss://api.deepgram.com/v2/listen',

                            'services.deepgram.flux_model' => 'flux-general-multi',

                            'services.deepgram.flux_keyterms' => [],
                        ],
                    ],

                    'translation' => [
                        'contract' => DeepLTranslationProvider::class,

                        'provider' => 'deepl',

                        'model' => 'latency_optimized',

                        'config' => [
                            'services.deepl.model_type' => 'latency_optimized',
                        ],
                    ],

                    'text_to_speech' => [
                        'contract' => OpenAIStreamingTextToSpeechProvider::class,

                        'provider' => 'openai',

                        'model' => 'gpt-4o-mini-tts-2025-12-15',

                        'config' => [
                            'services.openai.text_to_speech.model' => 'gpt-4o-mini-tts-2025-12-15',

                            'services.openai.text_to_speech.endpoint' => 'https://api.openai.com/v1/audio/speech',

                            'services.openai.text_to_speech.voice' => 'marin',
                        ],
                    ],
                ],

                'chirp3-deepl-openai' => [
                    'speech_to_text' => [
                        'contract' => BatchSpeechToTextStreamingAdapter::class,

                        'provider' => 'google',

                        'model' => 'chirp_3',

                        'config' => [
                            'services.google.speech.model' => 'chirp_3',

                            'services.google.speech.location' => 'eu',

                            'services.google.speech.api_endpoint' => 'eu-speech.googleapis.com',
                        ],
                    ],

                    'translation' => [
                        'contract' => DeepLTranslationProvider::class,

                        'provider' => 'deepl',

                        'model' => 'latency_optimized',

                        'config' => [
                            'services.deepl.model_type' => 'latency_optimized',
                        ],
                    ],

                    'text_to_speech' => [
                        'contract' => OpenAIStreamingTextToSpeechProvider::class,

                        'provider' => 'openai',

                        'model' => 'gpt-4o-mini-tts-2025-12-15',

                        'config' => [
                            'services.openai.text_to_speech.model' => 'gpt-4o-mini-tts-2025-12-15',

                            'services.openai.text_to_speech.endpoint' => 'https://api.openai.com/v1/audio/speech',

                            'services.openai.text_to_speech.voice' => 'marin',
                        ],
                    ],
                ],
            ],
        ],
    ],
];
