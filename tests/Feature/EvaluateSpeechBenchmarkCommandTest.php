<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->benchmarkDirectory = storage_path(
        'framework/testing/speech-evaluation',
    );

    File::deleteDirectory($this->benchmarkDirectory);

    File::ensureDirectoryExists(
        $this->benchmarkDirectory.'/test-profile',
    );

    File::put(
        $this->benchmarkDirectory
        .'/test-profile/results.json',
        json_encode(
            [
                'benchmark_version' => 1,
                'dataset_version' => 1,
                'profile' => 'test-profile',
                'provider' => 'fake',
                'model' => 'fake-model',
                'fixture_count' => 2,
                'results' => [
                    [
                        'phrase_id' => 'en-001',
                        'expected' => 'The distance is one hundred and fifty-four kilometers.',
                        'actual' => 'The distance is 154 km.',
                        'success' => true,
                        'latency_ms' => 100.0,
                    ],
                    [
                        'phrase_id' => 'en-002',
                        'expected' => 'Take this truck.',
                        'actual' => 'Take this track.',
                        'success' => true,
                        'latency_ms' => 200.0,
                    ],
                ],
            ],
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR,
        ),
    );

    config([
        'benchmarks.speech.results_path' => $this->benchmarkDirectory,
    ]);
});

afterEach(function () {
    File::deleteDirectory(
        $this->benchmarkDirectory,
    );
});

it('evaluates an existing speech benchmark', function () {
    $this->artisan(
        'benchmark:speech:evaluate',
        [
            'profile' => 'test-profile',
        ],
    )
        ->expectsOutput(
            'Evaluated speech benchmark: test-profile'
        )
        ->expectsOutput(
            'Successful: 2/2'
        )
        ->expectsOutput(
            'Content mismatches: 1'
        )
        ->assertSuccessful();

    $filePath =
        $this->benchmarkDirectory
        .'/test-profile/results.json';

    $benchmark = json_decode(
        File::get($filePath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($benchmark)
        ->toHaveKey('evaluation.version', 1)
        ->toHaveKey(
            'evaluation.summary.fixture_count',
            2,
        )
        ->toHaveKey(
            'evaluation.summary.successful_count',
            2,
        )
        ->toHaveKey(
            'evaluation.summary.normalization_only_count',
            1,
        )
        ->toHaveKey(
            'evaluation.summary.content_mismatch_count',
            1,
        );

    expect(
        $benchmark['results'][0]['evaluation']
    )
        ->toHaveKey('normalized_wer', 0.0)
        ->toHaveKey(
            'classification',
            'normalization_only',
        );

    expect(
        $benchmark['results'][1]['evaluation']
    )
        ->toHaveKey(
            'classification',
            'content_mismatch',
        );
});

it('fails when benchmark results do not exist', function () {
    $this->artisan(
        'benchmark:speech:evaluate',
        [
            'profile' => 'missing-profile',
        ],
    )
        ->expectsOutput(
            'Speech benchmark results not found: missing-profile'
        )
        ->assertFailed();
});
