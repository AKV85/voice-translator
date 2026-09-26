<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $basePath =
        storage_path(
            'framework/testing/pipeline-translation-evaluation',
        );

    File::deleteDirectory(
        $basePath,
    );

    File::ensureDirectoryExists(
        "{$basePath}/flux-openai-openai",
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
        "{$basePath}/flux-openai-openai/pipeline-results-ru-001.json",
        json_encode(
            [
                'benchmark_version' => 1,

                'benchmark_type' => 'pipeline_translation',

                'profile' => 'flux-openai-openai',

                'fixture_count' => 1,

                'run_count' => 5,

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

                                'stt_success' => true,

                                'translation_success' => true,

                                'tts_success' => true,

                                'audio_success' => true,

                                'translated_text' => 'Am I free or not free?',

                                'first_input_audio_provided_ms' => 1200.0,

                                'stt_completed_relative_to_input_end_ms' => 200.0,

                                'translation_latency_ms' => 300.0,

                                'translation_completed_relative_to_input_end_ms' => 500.0,

                                'tts_first_audio_chunk_latency_ms' => 480.0,

                                'tts_first_audible_available_latency_ms' => 500.0,

                                'first_tts_audio_chunk_relative_to_input_end_ms' => 980.0,

                                'raw_playback_first_audible_audio_relative_to_input_end_ms' => 990.0,

                                'first_audible_audio_available_relative_to_input_end_ms' => 1000.0,

                                'tts_duration_ms' => 900.0,

                                'tts_completed_relative_to_input_end_ms' => 1400.0,

                                'total_duration_ms' => 6500.0,

                                'failed_stage' => null,
                            ],

                            [
                                'run' => 2,

                                'success' => true,

                                'stt_success' => true,

                                'translation_success' => true,

                                'tts_success' => true,

                                'audio_success' => true,

                                /*
                                 * Different valid wording,
                                 * but negation is preserved.
                                 */
                                'translated_text' => "I'm free or not?",

                                'first_input_audio_provided_ms' => 1100.0,

                                'stt_completed_relative_to_input_end_ms' => 300.0,

                                'translation_latency_ms' => 200.0,

                                'translation_completed_relative_to_input_end_ms' => 500.0,

                                'tts_first_audio_chunk_latency_ms' => 580.0,

                                'tts_first_audible_available_latency_ms' => 600.0,

                                'first_tts_audio_chunk_relative_to_input_end_ms' => 1080.0,

                                'raw_playback_first_audible_audio_relative_to_input_end_ms' => 1090.0,

                                'first_audible_audio_available_relative_to_input_end_ms' => 1100.0,

                                'tts_duration_ms' => 1000.0,

                                'tts_completed_relative_to_input_end_ms' => 1500.0,

                                'total_duration_ms' => 6600.0,

                                'failed_stage' => null,
                            ],

                            [
                                'run' => 3,

                                'success' => true,

                                'stt_success' => true,

                                'translation_success' => true,

                                'tts_success' => true,

                                'audio_success' => false,

                                /*
                                 * Missing critical negation and
                                 * no audible translated audio.
                                 */
                                'translated_text' => 'I am free.',

                                'first_input_audio_provided_ms' => 1000.0,

                                'stt_completed_relative_to_input_end_ms' => 250.0,

                                'translation_latency_ms' => 250.0,

                                'translation_completed_relative_to_input_end_ms' => 500.0,

                                'tts_first_audio_chunk_latency_ms' => 700.0,

                                'tts_first_audible_available_latency_ms' => null,

                                'first_tts_audio_chunk_relative_to_input_end_ms' => 1200.0,

                                'raw_playback_first_audible_audio_relative_to_input_end_ms' => null,

                                'first_audible_audio_available_relative_to_input_end_ms' => null,

                                'tts_duration_ms' => 1200.0,

                                'tts_completed_relative_to_input_end_ms' => 1700.0,

                                'total_duration_ms' => 6800.0,

                                'failed_stage' => null,
                            ],

                            [
                                'run' => 4,

                                'success' => false,

                                'stt_success' => true,

                                'translation_success' => false,

                                'tts_success' => false,

                                'audio_success' => false,

                                'translated_text' => null,

                                'first_input_audio_provided_ms' => 1050.0,

                                'stt_completed_relative_to_input_end_ms' => 220.0,

                                'translation_latency_ms' => null,

                                'translation_completed_relative_to_input_end_ms' => null,

                                'tts_first_audio_chunk_latency_ms' => null,

                                'tts_first_audible_available_latency_ms' => null,

                                'first_tts_audio_chunk_relative_to_input_end_ms' => null,

                                'raw_playback_first_audible_audio_relative_to_input_end_ms' => null,

                                'first_audible_audio_available_relative_to_input_end_ms' => null,

                                'tts_duration_ms' => null,

                                'tts_completed_relative_to_input_end_ms' => null,

                                'total_duration_ms' => 5000.0,

                                'failed_stage' => 'translation',
                            ],

                            [
                                'run' => 5,

                                'success' => false,

                                'stt_success' => true,

                                'translation_success' => true,

                                'tts_success' => false,

                                'audio_success' => false,

                                /*
                                 * Translation is valid even though
                                 * the later TTS stage fails.
                                 */
                                'translated_text' => 'Am I free or not?',

                                'first_input_audio_provided_ms' => 1150.0,

                                'stt_completed_relative_to_input_end_ms' => 230.0,

                                'translation_latency_ms' => 180.0,

                                'translation_completed_relative_to_input_end_ms' => 410.0,

                                'tts_first_audio_chunk_latency_ms' => null,

                                'tts_first_audible_available_latency_ms' => null,

                                'first_tts_audio_chunk_relative_to_input_end_ms' => null,

                                'raw_playback_first_audible_audio_relative_to_input_end_ms' => null,

                                'first_audible_audio_available_relative_to_input_end_ms' => null,

                                'tts_duration_ms' => null,

                                'tts_completed_relative_to_input_end_ms' => null,

                                'total_duration_ms' => 5300.0,

                                'failed_stage' => 'tts',
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
            'framework/testing/pipeline-translation-evaluation',
        ),
    );
});

it(
    'evaluates pipeline translation quality reliability and latency',
    function () {
        $this->artisan(
            'benchmark:translation:pipeline:evaluate',
            [
                'profile' => 'flux-openai-openai',

                '--phrase' => 'ru-001',
            ],
        )
            ->expectsOutput(
                'Pipeline translation benchmark evaluation: flux-openai-openai',
            )
            ->expectsOutput(
                'Phrase: ru-001',
            )
            ->expectsOutput(
                'Successful runs: 3/5',
            )
            ->expectsOutput(
                'STT successful: 5/5 (100.00%)',
            )
            ->expectsOutput(
                'Translation successful: 4/5 (80.00%)',
            )
            ->expectsOutput(
                'TTS successful: 3/4 (75.00%)',
            )
            ->expectsOutput(
                'Manual review required: 1',
            )
            ->expectsOutput(
                'Critical elements: 3/4 (75.00%)',
            )
            ->expectsOutput(
                'Runs with missing critical elements: 1',
            )
            ->expectsOutput(
                'Audible audio detected: 2/3 (66.67%)',
            )
            ->expectsOutput(
                'STT after STOP:',
            )
            ->expectsOutput(
                'Translation latency:',
            )
            ->expectsOutput(
                'TTS -> audible:',
            )
            ->expectsOutput(
                'STOP -> audio ready:',
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
                "{$resultsPath}/flux-openai-openai/pipeline-results-ru-001.json",
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
                'stages'
            ][
                'speech_to_text'
            ][
                'successful'
            ],
        )->toBe(5);

        expect(
            $evaluated[
                'evaluation'
            ][
                'stages'
            ][
                'translation'
            ][
                'attempted'
            ],
        )->toBe(5);

        expect(
            $evaluated[
                'evaluation'
            ][
                'stages'
            ][
                'translation'
            ][
                'successful'
            ],
        )->toBe(4);

        expect(
            $evaluated[
                'evaluation'
            ][
                'stages'
            ][
                'text_to_speech'
            ][
                'attempted'
            ],
        )->toBe(4);

        expect(
            $evaluated[
                'evaluation'
            ][
                'stages'
            ][
                'text_to_speech'
            ][
                'successful'
            ],
        )->toBe(3);

        expect(
            $evaluated[
                'evaluation'
            ][
                'critical_elements'
            ][
                'passed'
            ],
        )->toBe(3);

        expect(
            $evaluated[
                'evaluation'
            ][
                'critical_elements'
            ][
                'total'
            ],
        )->toBe(4);

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
        )->toEqual(1050.0);

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
         * Different valid wording alone does not
         * force manual review.
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

        /*
         * Translation quality is still evaluated when
         * a later TTS stage fails.
         */
        expect(
            $evaluated[
                'results'
            ][0][
                'runs'
            ][4][
                'evaluation'
            ][
                'critical_elements_ok'
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
                'manual_review_reasons'
            ],
        )->toBe([
            'missing_critical_elements',
            'audible_audio_not_detected',
        ]);
    },
);

it(
    'fails when pipeline translation benchmark results do not exist',
    function () {
        $this->artisan(
            'benchmark:translation:pipeline:evaluate',
            [
                'profile' => 'missing',

                '--pair' => 'ru-en',
            ],
        )
            ->assertExitCode(1);
    },
);
