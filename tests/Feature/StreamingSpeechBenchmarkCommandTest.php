<?php

use App\Contracts\StreamingSpeechToTextProvider;
use App\DTO\StreamingTranscriptionResult;
use App\Enums\Language;
use Closure;
use Illuminate\Support\Facades\File;
use RuntimeException;

class BenchmarkStreamingFakeProvider implements StreamingSpeechToTextProvider
{
    public function transcribe(
        iterable $audioChunks,
        string $mimeType,
        Language $sourceLanguage,
        Closure $onTranscript,
    ): StreamingTranscriptionResult {
        $audio = '';
        $firstChunk = true;

        foreach ($audioChunks as $chunk) {
            $audio .= $chunk;

            if ($firstChunk) {
                $onTranscript(
                    'Fake interim',
                    false,
                );

                $firstChunk = false;
            }
        }

        if ($audio === 'FAIL_STREAM') {
            throw new RuntimeException(
                'Simulated streaming provider failure.'
            );
        }

        $onTranscript(
            'Fake final transcription',
            true,
        );

        return new StreamingTranscriptionResult(
            text: 'Fake final transcription',
        );
    }
}

beforeEach(function () {
    $this->benchmarkDirectory = storage_path(
        'framework/testing/streaming-speech-benchmark'
    );

    File::deleteDirectory(
        $this->benchmarkDirectory
    );

    File::ensureDirectoryExists(
        $this->benchmarkDirectory.'/audio/raw'
    );

    File::put(
        $this->benchmarkDirectory
        .'/audio/raw/ru-001.webm',
        'SUCCESS_STREAM'
    );

    File::put(
        $this->benchmarkDirectory
        .'/audio/raw/en-001.webm',
        'FAIL_STREAM'
    );

    File::put(
        $this->benchmarkDirectory.'/phrases.json',
        json_encode(
            [
                'version' => 1,
                'languages' => [
                    'ru',
                    'en',
                ],
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

        'benchmarks.speech.streaming.chunk_size_bytes' => 4,

        'benchmarks.speech.streaming.chunk_interval_ms' => 0,

        'benchmarks.speech.streaming.providers.fake-stream' => [
            'contract' => BenchmarkStreamingFakeProvider::class,

            'provider' => 'fake',

            'model' => 'fake-stream-model',

            'config' => [],
        ],
    ]);
});

afterEach(function () {
    File::deleteDirectory(
        $this->benchmarkDirectory
    );
});

it(
    'runs the streaming speech benchmark and stores structured results',
    function () {
        $this->artisan(
            'benchmark:speech:stream',
            [
                '--provider' => 'fake-stream',
            ],
        )
            ->expectsOutput(
                'Running streaming speech benchmark profile: fake-stream'
            )
            ->expectsOutput(
                'Provider: fake'
            )
            ->expectsOutput(
                'Model: fake-stream-model'
            )
            ->expectsOutput(
                'Chunk size: 4 bytes'
            )
            ->expectsOutput(
                'Chunk interval: 0 ms'
            )
            ->expectsOutput(
                'Completed: 1/2 successful.'
            )
            ->assertSuccessful();

        $resultsPath =
            $this->benchmarkDirectory
            .'/results/fake-stream/streaming-results.json';

        expect($resultsPath)
            ->toBeFile();

        $benchmark = json_decode(
            File::get($resultsPath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($benchmark)
            ->toHaveKey(
                'benchmark_version',
                1,
            )
            ->toHaveKey(
                'benchmark_type',
                'streaming',
            )
            ->toHaveKey(
                'dataset_version',
                1,
            )
            ->toHaveKey(
                'profile',
                'fake-stream',
            )
            ->toHaveKey(
                'provider',
                'fake',
            )
            ->toHaveKey(
                'model',
                'fake-stream-model',
            )
            ->toHaveKey(
                'chunk_size_bytes',
                4,
            )
            ->toHaveKey(
                'chunk_interval_ms',
                0,
            )
            ->toHaveKey(
                'fixture_count',
                2,
            )
            ->toHaveKeys([
                'started_at',
                'completed_at',
                'results',
            ]);

        expect(
            $benchmark['results']
        )->toHaveCount(2);

        $successfulResult =
            $benchmark['results'][0];

        expect($successfulResult)
            ->toMatchArray([
                'phrase_id' => 'ru-001',
                'language' => 'ru',
                'category' => 'simple',
                'expected' => 'Тестовая фраза.',
                'actual' => 'Fake final transcription',
                'audio' => 'audio/raw/ru-001.webm',
                'success' => true,
                'event_count' => 2,
                'error' => null,
                'provider' => 'fake',
                'model' => 'fake-stream-model',
            ]);

        expect(
            $successfulResult[
                'time_to_first_transcript_ms'
            ]
        )->toBeGreaterThanOrEqual(0);

        expect(
            $successfulResult[
                'time_to_final_transcript_ms'
            ]
        )->toBeGreaterThanOrEqual(0);

        expect(
            $successfulResult[
                'finalization_latency_ms'
            ]
        )->toBeGreaterThanOrEqual(0);

        expect(
            $successfulResult[
                'total_duration_ms'
            ]
        )->toBeGreaterThanOrEqual(0);

        expect(
            $successfulResult['events']
        )->toHaveCount(2);

        expect(
            $successfulResult['events'][0]
        )->toMatchArray([
            'text' => 'Fake interim',
            'is_final' => false,
        ]);

        expect(
            $successfulResult['events'][1]
        )->toMatchArray([
            'text' => 'Fake final transcription',
            'is_final' => true,
        ]);

        $failedResult =
            $benchmark['results'][1];

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
                'model' => 'fake-stream-model',
            ]);

        expect(
            $failedResult[
                'total_duration_ms'
            ]
        )->toBeGreaterThanOrEqual(0);
    }
);

it(
    'rejects an unknown streaming speech benchmark provider',
    function () {
        $this->artisan(
            'benchmark:speech:stream',
            [
                '--provider' => 'unknown',
            ],
        )
            ->expectsOutput(
                'Unknown streaming speech benchmark provider: unknown'
            )
            ->assertFailed();
    }
);

it(
    'requires a streaming speech benchmark provider',
    function () {
        $this->artisan(
            'benchmark:speech:stream'
        )
            ->expectsOutput(
                'Streaming speech benchmark provider is required.'
            )
            ->assertFailed();
    }
);
