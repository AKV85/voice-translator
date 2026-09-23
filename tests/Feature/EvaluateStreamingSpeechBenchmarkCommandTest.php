<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $resultsPath = storage_path(
        'framework/testing/streaming-speech-benchmark',
    );

    File::deleteDirectory(
        $resultsPath,
    );

    File::ensureDirectoryExists(
        $resultsPath,
    );

    config([
        'benchmarks.speech.results_path' => $resultsPath,
    ]);
});

afterEach(function () {
    File::deleteDirectory(
        storage_path(
            'framework/testing/streaming-speech-benchmark',
        ),
    );
});

it(
    'evaluates streaming speech accuracy and latency',
    function () {
        $resultsPath = config(
            'benchmarks.speech.results_path',
        );

        expect($resultsPath)
            ->toBeString();

        $profilePath =
            "{$resultsPath}/deepgram-flux";

        File::ensureDirectoryExists(
            $profilePath,
        );

        $filePath =
            "{$profilePath}/streaming-results.json";

        File::put(
            $filePath,
            json_encode(
                [
                    'benchmark_version' => 1,
                    'benchmark_type' => 'streaming',
                    'profile' => 'deepgram-flux',
                    'provider' => 'deepgram',
                    'model' => 'flux-general-multi',

                    'results' => [
                        [
                            'phrase_id' => 'en-001',
                            'expected' => 'I am ready.',
                            'actual' => "I'm ready.",
                            'success' => true,

                            'audio_duration_ms' => 4200.0,

                            'time_to_first_transcript_ms' => 2200.0,

                            'time_to_final_transcript_ms' => 5000.0,

                            'finalization_latency_ms' => 260.0,

                            'total_duration_ms' => 5015.0,

                            'preparation_duration_ms' => 400.0,
                        ],

                        [
                            'phrase_id' => 'en-002',
                            'expected' => 'hello world',
                            'actual' => 'hello there',
                            'success' => true,

                            'audio_duration_ms' => 5200.0,

                            'time_to_first_transcript_ms' => 1800.0,

                            'time_to_final_transcript_ms' => 6000.0,

                            'finalization_latency_ms' => 300.0,

                            'total_duration_ms' => 6010.0,

                            'preparation_duration_ms' => 450.0,
                        ],
                    ],
                ],
                JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR,
            ).PHP_EOL,
        );

        $this->artisan(
            'benchmark:speech:stream:evaluate',
            [
                'profile' => 'deepgram-flux',
            ],
        )
            ->assertExitCode(0);

        $evaluated = json_decode(
            File::get($filePath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($evaluated)
            ->toBeArray();

        $summary =
            $evaluated['evaluation']['summary'];

        expect(
            $summary['fixture_count']
        )->toBe(2);

        expect(
            $summary['successful_count']
        )->toBe(2);

        expect(
            $summary['normalization_only_count']
        )->toBe(1);

        expect(
            $summary['content_mismatch_count']
        )->toBe(1);

        expect(
            $summary['average_normalized_wer']
        )->toBe(0.25);

        expect(
            $evaluated['results'][0]['evaluation']['classification']
        )->toBe(
            'normalization_only',
        );

        expect(
            $evaluated['results'][0]['evaluation']['normalized_wer']
        )->toBe(0);

        expect(
            $evaluated['results'][1]['evaluation']['classification']
        )->toBe(
            'content_mismatch',
        );

        expect(
            $evaluated['results'][1]['evaluation']['normalized_wer']
        )->toBe(0.5);

        $latency =
            $evaluated['evaluation']['streaming_latency'];

        expect(
            $latency['time_to_first_transcript_ms']['average']
        )->toBe(2000);

        expect(
            $latency['finalization_latency_ms']['average']
        )->toBe(280);

        expect(
            $latency['finalization_latency_ms']['median']
        )->toBe(280);

        expect(
            $latency['finalization_latency_ms']['p95']
        )->toBe(298);

        expect(
            $latency['estimated_provider_setup_ms']['average']
        )->toBe(520);

        expect(
            $latency['estimated_provider_setup_ms']['median']
        )->toBe(520);

        expect(
            $latency['estimated_provider_setup_ms']['p95']
        )->toBe(538);
    },
);

it(
    'fails when streaming benchmark results do not exist',
    function () {
        $this->artisan(
            'benchmark:speech:stream:evaluate',
            [
                'profile' => 'missing-profile',
            ],
        )
            ->expectsOutput(
                'Streaming speech benchmark results not found: missing-profile'
            )
            ->assertExitCode(1);
    },
);
