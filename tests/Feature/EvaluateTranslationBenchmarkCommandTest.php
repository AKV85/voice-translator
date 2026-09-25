<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $basePath = storage_path(
        'framework/testing/translation-evaluation',
    );

    File::deleteDirectory(
        $basePath,
    );

    File::ensureDirectoryExists(
        "{$basePath}/google-nmt",
    );

    $datasetPath =
        "{$basePath}/phrases.json";

    config([
        'benchmarks.translation.results_path' => $basePath,

        'benchmarks.translation.dataset_path' => $datasetPath,
    ]);

    File::put(
        $datasetPath,
        json_encode(
            [
                'version' => 1,

                'phrases' => [
                    [
                        'id' => 'en-ru-001',

                        'critical_elements' => [
                            [
                                'name' => 'truck',

                                'accepted' => [
                                    'грузовик',
                                ],
                            ],
                        ],
                    ],

                    [
                        'id' => 'en-ru-002',

                        'critical_elements' => [
                            [
                                'name' => 'trailer_identifier',

                                'accepted' => [
                                    'ZZ 546',
                                    'ZZ-546',
                                ],
                            ],

                            [
                                'name' => 'leave_action',

                                'accepted' => [
                                    'оставьте',
                                    'оставить',
                                ],
                            ],
                        ],
                    ],

                    [
                        'id' => 'en-ru-003',

                        'critical_elements' => [
                            [
                                'name' => 'truck_negation',

                                'accepted' => [
                                    'не этот грузовик',
                                ],
                            ],

                            [
                                'name' => 'take_next_vehicle',

                                'accepted' => [
                                    'возьми следующий',
                                    'возьмите следующий',
                                    'бери следующий',
                                    'берите следующий',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR,
        ).PHP_EOL,
    );

    File::put(
        "{$basePath}/google-nmt/results-en-ru.json",
        json_encode(
            [
                'benchmark_version' => 1,
                'benchmark_type' => 'translation',
                'profile' => 'google-nmt',
                'provider' => 'google',
                'model' => 'general/nmt',
                'language_pair' => 'en-ru',
                'fixture_count' => 3,

                'results' => [
                    [
                        'phrase_id' => 'en-ru-001',

                        'success' => true,

                        'actual' => 'Мне нужен грузовик.',

                        'latency_ms' => 2400.0,

                        'critical_elements' => [
                            [
                                'name' => 'stale_value',

                                'accepted' => [
                                    'this must not be used',
                                ],
                            ],
                        ],
                    ],

                    [
                        'phrase_id' => 'en-ru-002',

                        'success' => true,

                        'actual' => 'Оставьте прицеп ZZ 546 на парковке.',

                        'latency_ms' => 200.0,

                        'critical_elements' => [],
                    ],

                    [
                        'phrase_id' => 'en-ru-003',

                        'success' => true,

                        'actual' => 'Нет, не этот грузовик, садитесь на следующий.',

                        'latency_ms' => 400.0,

                        'critical_elements' => [
                            [
                                'name' => 'old_next_vehicle',

                                'accepted' => [
                                    'следующий',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR,
        ).PHP_EOL,
    );
});

afterEach(function () {
    File::deleteDirectory(
        storage_path(
            'framework/testing/translation-evaluation',
        ),
    );
});

it(
    'uses current dataset critical elements when evaluating existing results',
    function () {
        $this->artisan(
            'benchmark:translation:evaluate',
            [
                'profile' => 'google-nmt',

                'pair' => 'en-ru',
            ],
        )
            ->expectsOutput(
                'Translation benchmark evaluation: google-nmt / en-ru'
            )
            ->expectsOutput(
                'Successful: 3/3'
            )
            ->expectsOutput(
                'Critical elements: 4/5 (80%)'
            )
            ->expectsOutput(
                'Fixtures with missing critical elements: 1'
            )
            ->expectsOutput(
                'Cold start: 2400.00 ms'
            )
            ->expectsOutput(
                '  avg: 300.00 ms'
            )
            ->expectsOutput(
                '  median: 300.00 ms'
            )
            ->expectsOutput(
                '  p95: 390.00 ms'
            )
            ->expectsOutput(
                '  min: 200.00 ms'
            )
            ->expectsOutput(
                '  max: 400.00 ms'
            )
            ->expectsOutput(
                '  en-ru-003: take_next_vehicle'
            )
            ->assertExitCode(0);

        $resultsPath = config(
            'benchmarks.translation.results_path',
        );

        expect($resultsPath)
            ->toBeString();

        $evaluated = json_decode(
            File::get(
                "{$resultsPath}/google-nmt/results-en-ru.json"
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect(
            $evaluated[
                'evaluation'
            ][
                'critical_elements'
            ][
                'passed'
            ]
        )->toBe(4);

        expect(
            $evaluated[
                'evaluation'
            ][
                'critical_elements'
            ][
                'total'
            ]
        )->toBe(5);

        expect(
            $evaluated[
                'evaluation'
            ][
                'latency'
            ][
                'cold_start'
            ][
                'latency_ms'
            ]
        )->toBe(2400);

        expect(
            $evaluated[
                'evaluation'
            ][
                'latency'
            ][
                'warm'
            ][
                'avg_ms'
            ]
        )->toBe(300);

        expect(
            $evaluated[
                'results'
            ][2][
                'evaluation'
            ][
                'critical_elements_ok'
            ]
        )->toBeFalse();

        expect(
            $evaluated[
                'results'
            ][2][
                'evaluation'
            ][
                'missing_critical_elements'
            ]
        )->toBe([
            'take_next_vehicle',
        ]);
    },
);

it(
    'fails when benchmark results do not exist',
    function () {
        $this->artisan(
            'benchmark:translation:evaluate',
            [
                'profile' => 'missing',

                'pair' => 'ru-en',
            ],
        )
            ->assertExitCode(1);
    },
);
