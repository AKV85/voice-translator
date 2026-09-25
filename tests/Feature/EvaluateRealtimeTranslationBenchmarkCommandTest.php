<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $basePath = storage_path(
        'framework/testing/realtime-translation-evaluation',
    );

    File::deleteDirectory(
        $basePath,
    );

    File::ensureDirectoryExists(
        "{$basePath}/openai-realtime",
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
                        'id' => 'ru-en-001',

                        'source_language' => 'ru',

                        'target_language' => 'en',

                        'source' => 'Я свободна или не свободна?',

                        'reference' => 'Am I free or not free?',

                        'critical_elements' => [
                            [
                                'name' => 'negation',

                                'accepted' => [
                                    'not',
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
        "{$basePath}/openai-realtime/realtime-results-ru-001.json",
        json_encode(
            [
                'benchmark_version' => 1,

                'benchmark_type' => 'realtime_translation',

                'profile' => 'openai-realtime',

                'provider' => 'openai',

                'model' => 'gpt-realtime-translate',

                'fixture_count' => 1,

                'run_count' => 3,

                'results' => [
                    [
                        'phrase_id' => 'ru-001',

                        'translation_fixture_id' => 'ru-en-001',

                        'reference' => 'stale reference',

                        'critical_elements' => [
                            [
                                'name' => 'stale_element',

                                'accepted' => [
                                    'must not be used',
                                ],
                            ],
                        ],

                        'runs' => [
                            [
                                'run' => 1,

                                'success' => true,

                                'translated_text' => 'Am I free or not free?',

                                'first_input_audio_sent_ms' => 1800.0,

                                'input_finished_ms' => 6660.0,

                                'first_audio_packet_relative_to_input_end_ms' => -3800.0,

                                'raw_playback_first_audible_audio_relative_to_input_end_ms' => -1000.0,

                                'first_audible_audio_available_relative_to_first_input_ms' => 3100.0,

                                'first_audible_audio_available_relative_to_input_end_ms' => -1700.0,

                                'session_closed_ms' => 9600.0,
                            ],

                            [
                                'run' => 2,

                                'success' => true,

                                /*
                                 * Not reference-exact, but still preserves
                                 * the configured critical element.
                                 */
                                'translated_text' => "I'm free or not?",

                                'first_input_audio_sent_ms' => 1700.0,

                                'input_finished_ms' => 6560.0,

                                'first_audio_packet_relative_to_input_end_ms' => -3600.0,

                                'raw_playback_first_audible_audio_relative_to_input_end_ms' => 400.0,

                                'first_audible_audio_available_relative_to_first_input_ms' => 5110.0,

                                'first_audible_audio_available_relative_to_input_end_ms' => 250.0,

                                'session_closed_ms' => 10000.0,
                            ],

                            [
                                'run' => 3,

                                'success' => true,

                                /*
                                 * Missing critical element and no audible
                                 * translated audio detected.
                                 */
                                'translated_text' => 'I am free.',

                                'first_input_audio_sent_ms' => 1600.0,

                                'input_finished_ms' => 6460.0,

                                'first_audio_packet_relative_to_input_end_ms' => 500.0,

                                'raw_playback_first_audible_audio_relative_to_input_end_ms' => null,

                                'first_audible_audio_available_relative_to_first_input_ms' => null,

                                'first_audible_audio_available_relative_to_input_end_ms' => null,

                                'session_closed_ms' => 9700.0,
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
            'framework/testing/realtime-translation-evaluation',
        ),
    );
});

it(
    'evaluates realtime translation runs using the current translation dataset',
    function () {
        $this->artisan(
            'benchmark:translation:realtime:evaluate',
            [
                'profile' => 'openai-realtime',

                '--phrase' => 'ru-001',
            ],
        )
            ->expectsOutput(
                'Realtime translation benchmark evaluation: openai-realtime',
            )
            ->expectsOutput(
                'Phrase: ru-001',
            )
            ->expectsOutput(
                'Successful runs: 3/3',
            )
            ->expectsOutput(
                'Manual review required: 1',
            )
            ->expectsOutput(
                'Critical elements: 2/3 (66.67%)',
            )
            ->expectsOutput(
                'Runs with missing critical elements: 1',
            )
            ->expectsOutput(
                'Audible audio detected: 2/3 (66.67%)',
            )
            ->expectsOutput(
                'STOP -> audio ready:',
            )
            ->expectsOutput(
                'Audible available relative to input end:',
            )
            ->expectsOutput(
                'Raw playback audible relative to input end:',
            )
            ->expectsOutput(
                'Audible available from first input:',
            )
            ->expectsOutput(
                'Session close after input end:',
            )
            ->expectsOutput(
                'Missing critical elements:',
            )
            ->expectsOutput(
                '  ru-001 run 3: negation',
            )
            ->assertExitCode(0);

        $resultsPath = config(
            'benchmarks.translation.results_path',
        );

        expect(
            $resultsPath,
        )->toBeString();

        $evaluated = json_decode(
            File::get(
                "{$resultsPath}/openai-realtime/realtime-results-ru-001.json",
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect(
            $evaluated[
                'evaluation'
            ][
                'successful_runs'
            ],
        )->toBe(3);

        expect(
            $evaluated[
                'evaluation'
            ][
                'manual_review_required_runs'
            ],
        )->toBe(1);

        expect(
            $evaluated[
                'evaluation'
            ][
                'critical_elements'
            ][
                'passed'
            ],
        )->toBe(2);

        expect(
            $evaluated[
                'evaluation'
            ][
                'critical_elements'
            ][
                'total'
            ],
        )->toBe(3);

        expect(
            $evaluated[
                'evaluation'
            ][
                'audio_output'
            ][
                'audible_detected_runs'
            ],
        )->toBe(2);

        expect(
            $evaluated[
                'evaluation'
            ][
                'audio_output'
            ][
                'audible_missing_runs'
            ],
        )->toBe(1);

        expect(
            $evaluated[
                'evaluation'
            ][
                'audio_output'
            ][
                'audible_detection_rate_percent'
            ],
        )->toEqual(66.67);

        expect(
            $evaluated[
                'evaluation'
            ][
                'latency'
            ][
                'stop_to_audio_ready_ms'
            ][
                'count'
            ],
        )->toBe(2);

        expect(
            $evaluated[
                'evaluation'
            ][
                'latency'
            ][
                'stop_to_audio_ready_ms'
            ][
                'median_ms'
            ],
        )->toEqual(125.0);

        expect(
            $evaluated[
                'evaluation'
            ][
                'latency'
            ][
                'stop_to_audio_ready_ms'
            ][
                'avg_ms'
            ],
        )->toEqual(125.0);

        expect(
            $evaluated[
                'evaluation'
            ][
                'latency'
            ][
                'stop_to_audio_ready_ms'
            ][
                'min_ms'
            ],
        )->toEqual(0.0);

        expect(
            $evaluated[
                'evaluation'
            ][
                'latency'
            ][
                'stop_to_audio_ready_ms'
            ][
                'max_ms'
            ],
        )->toEqual(250.0);

        expect(
            $evaluated[
                'results'
            ][0][
                'reference'
            ],
        )->toBe(
            'Am I free or not free?',
        );

        expect(
            $evaluated[
                'results'
            ][0][
                'critical_elements'
            ][0][
                'name'
            ],
        )->toBe(
            'negation',
        );

        /*
         * Different valid wording by itself must not force
         * manual review.
         */
        expect(
            $evaluated[
                'results'
            ][0][
                'runs'
            ][1][
                'evaluation'
            ][
                'normalized_reference_exact'
            ],
        )->toBeFalse();

        expect(
            $evaluated[
                'results'
            ][0][
                'runs'
            ][1][
                'evaluation'
            ][
                'manual_review_required'
            ],
        )->toBeFalse();

        expect(
            $evaluated[
                'results'
            ][0][
                'runs'
            ][2][
                'evaluation'
            ][
                'manual_review_required'
            ],
        )->toBeTrue();

        expect(
            $evaluated[
                'results'
            ][0][
                'runs'
            ][2][
                'evaluation'
            ][
                'missing_critical_elements'
            ],
        )->toBe([
            'negation',
        ]);

        expect(
            $evaluated[
                'results'
            ][0][
                'runs'
            ][2][
                'evaluation'
            ][
                'manual_review_reasons'
            ],
        )->toBe([
            'missing_critical_elements',
            'audible_audio_not_detected',
        ]);
    },
);

it(
    'fails when realtime translation benchmark results do not exist',
    function () {
        $this->artisan(
            'benchmark:translation:realtime:evaluate',
            [
                'profile' => 'missing',

                '--pair' => 'ru-en',
            ],
        )
            ->assertExitCode(1);
    },
);
