<?php

use App\Contracts\SpeechToTextProvider;
use App\DTO\AudioInput;
use App\DTO\TranscriptionResult;
use App\Enums\Language;
use Illuminate\Support\Facades\File;

class BenchmarkSpeechFakeProvider implements SpeechToTextProvider
{
    public function transcribe(
        AudioInput $audio,
        Language $sourceLanguage,
    ): TranscriptionResult {
        if ($audio->content === 'FAIL_AUDIO') {
            throw new RuntimeException('Simulated provider failure.');
        }

        return new TranscriptionResult(
            text: 'Fake transcription',
        );
    }
}

beforeEach(function () {
    $this->benchmarkDirectory = storage_path(
        'framework/testing/speech-benchmark'
    );

    File::deleteDirectory($this->benchmarkDirectory);

    File::ensureDirectoryExists(
        $this->benchmarkDirectory.'/audio/raw'
    );

    File::put(
        $this->benchmarkDirectory.'/audio/raw/ru-001.webm',
        'SUCCESS_AUDIO',
    );

    File::put(
        $this->benchmarkDirectory.'/audio/raw/en-001.webm',
        'FAIL_AUDIO',
    );

    File::put(
        $this->benchmarkDirectory.'/phrases.json',
        json_encode(
            [
                'version' => 1,
                'languages' => ['ru', 'en'],
                'phrases' => [
                    [
                        'id' => 'ru-001',
                        'language' => 'ru',
                        'category' => 'simple',
                        'expected' => 'Тестовая фраза.',
                        'audio' => 'audio/raw/ru-001.webm',
                    ],
                    [
                        'id' => 'en-001',
                        'language' => 'en',
                        'category' => 'simple',
                        'expected' => 'Test phrase.',
                        'audio' => 'audio/raw/en-001.webm',
                    ],
                ],
            ],
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR,
        ),
    );

    config([
        'benchmarks.speech.dataset_path' => $this->benchmarkDirectory.'/phrases.json',

        'benchmarks.speech.results_path' => $this->benchmarkDirectory.'/results',

        'benchmarks.speech.providers.fake-model' => [
            'contract' => BenchmarkSpeechFakeProvider::class,
            'provider' => 'fake',
            'model' => 'test-model',
            'config' => [],
        ],
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->benchmarkDirectory);
});

it('runs the speech benchmark and stores structured results', function () {
    $this->artisan('benchmark:speech', [
        '--provider' => 'fake-model',
    ])
        ->expectsOutput(
            'Running speech benchmark profile: fake-model'
        )
        ->expectsOutput(
            'Provider: fake'
        )
        ->expectsOutput(
            'Model: test-model'
        )
        ->expectsOutput(
            'Completed: 1/2 successful.'
        )
        ->assertSuccessful();

    $resultsPath =
        $this->benchmarkDirectory
        .'/results/fake-model/results.json';

    expect($resultsPath)->toBeFile();

    $benchmark = json_decode(
        File::get($resultsPath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($benchmark)
        ->toHaveKey('benchmark_version', 1)
        ->toHaveKey('dataset_version', 1)
        ->toHaveKey('profile', 'fake-model')
        ->toHaveKey('provider', 'fake')
        ->toHaveKey('model', 'test-model')
        ->toHaveKey('fixture_count', 2)
        ->toHaveKeys([
            'started_at',
            'completed_at',
            'results',
        ]);

    expect($benchmark['results'])->toHaveCount(2);

    $successfulResult = $benchmark['results'][0];

    expect($successfulResult)
        ->toMatchArray([
            'phrase_id' => 'ru-001',
            'language' => 'ru',
            'category' => 'simple',
            'expected' => 'Тестовая фраза.',
            'actual' => 'Fake transcription',
            'audio' => 'audio/raw/ru-001.webm',
            'success' => true,
            'error' => null,
            'provider' => 'fake',
            'model' => 'test-model',
        ]);

    expect($successfulResult['latency_ms'])
        ->toBeFloat()
        ->toBeGreaterThanOrEqual(0);

    $failedResult = $benchmark['results'][1];

    expect($failedResult)
        ->toMatchArray([
            'phrase_id' => 'en-001',
            'language' => 'en',
            'category' => 'simple',
            'expected' => 'Test phrase.',
            'actual' => null,
            'audio' => 'audio/raw/en-001.webm',
            'success' => false,
            'error' => RuntimeException::class,
            'provider' => 'fake',
            'model' => 'test-model',
        ]);

    expect($failedResult['latency_ms'])
        ->toBeFloat()
        ->toBeGreaterThanOrEqual(0);
});

it('rejects an unknown speech benchmark provider', function () {
    $this->artisan('benchmark:speech', [
        '--provider' => 'unknown',
    ])
        ->expectsOutput(
            'Unknown speech benchmark provider: unknown'
        )
        ->assertFailed();
});
