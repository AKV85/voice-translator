<?php

use App\Contracts\Pcm16AudioConverter;
use App\Contracts\RealtimeTranslationProvider;
use App\DTO\RealtimeTranslationResult;
use App\Enums\Language;
use App\Exceptions\RealtimeTranslationException;
use App\Services\Benchmark\RealtimeTranslationBenchmarkRunner;
use Closure;
use Illuminate\Support\Facades\File;
use RuntimeException;

beforeEach(function () {
    $this->benchmarkDirectory =
        storage_path(
            'framework/testing/realtime-translation-runner',
        );

    File::deleteDirectory(
        $this->benchmarkDirectory,
    );

    File::ensureDirectoryExists(
        $this->benchmarkDirectory
        .'/audio/raw',
    );

    $this->speechDatasetPath =
        $this->benchmarkDirectory
        .'/speech.json';

    $this->translationDatasetPath =
        $this->benchmarkDirectory
        .'/translation.json';

    File::put(
        $this->benchmarkDirectory
        .'/audio/raw/ru-001.webm',
        'fake-audio',
    );

    File::put(
        $this->speechDatasetPath,
        json_encode(
            [
                'version' => 1,

                'phrases' => [
                    [
                        'id' => 'ru-001',
                        'language' => 'ru',
                        'category' => 'simple',
                        'expected' => 'Я свободна или не свободна?',
                        'audio' => 'audio/raw/ru-001.webm',
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_UNICODE,
        ),
    );

    File::put(
        $this->translationDatasetPath,
        json_encode(
            [
                'version' => 1,

                'phrases' => [
                    [
                        'id' => 'ru-en-001',
                        'source_language' => 'ru',
                        'target_language' => 'en',
                        'category' => 'simple',
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
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_UNICODE,
        ),
    );
});

afterEach(function () {
    File::deleteDirectory(
        storage_path(
            'framework/testing/realtime-translation-runner',
        ),
    );
});

it(
    'reports run progress and preserves the full exception chain',
    function () {
        $runner =
            new RealtimeTranslationBenchmarkRunner(
                new BenchmarkRealtimeFakePcm16AudioConverter,
            );

        $provider =
            new BenchmarkRealtimeFakeTranslationProvider;

        $started = [];
        $completed = [];

        $benchmark =
            $runner->run(
                provider: $provider,
                profileName: 'fake-realtime',
                providerName: 'fake',
                modelName: 'fake-model',
                speechDatasetPath: $this->speechDatasetPath,
                translationDatasetPath: $this->translationDatasetPath,
                chunkDurationMs: 100,
                runsPerFixture: 2,
                languagePair: 'ru-en',

                onRunStarted: function (
                    array $progress,
                ) use (
                    &$started,
                ): void {
                    $started[] =
                        $progress;
                },

                onRunCompleted: function (
                    array $progress,
                ) use (
                    &$completed,
                ): void {
                    $completed[] =
                        $progress;
                },
            );

        expect($started)
            ->toHaveCount(2);

        expect(
            $started[0],
        )->toMatchArray([
            'fixture_index' => 1,
            'fixture_count' => 1,
            'phrase_id' => 'ru-001',
            'run_number' => 1,
            'runs_per_fixture' => 2,
        ]);

        expect(
            $started[1][
                'run_number'
            ],
        )->toBe(2);

        expect($completed)
            ->toHaveCount(2);

        expect(
            $completed[0][
                'wall_duration_ms'
            ],
        )->toBeFloat();

        expect(
            $completed[0][
                'result'
            ][
                'success'
            ],
        )->toBeTrue();

        expect(
            $completed[1][
                'result'
            ][
                'success'
            ],
        )->toBeFalse();

        $failed =
            $benchmark[
                'results'
            ][0][
                'runs'
            ][1];

        expect(
            $failed['error'],
        )->toBe(
            RealtimeTranslationException::class,
        );

        expect(
            $failed[
                'error_message'
            ],
        )->toBe(
            'Fake realtime translation failed.',
        );

        expect(
            $failed[
                'previous_error'
            ],
        )->toBe(
            RuntimeException::class,
        );

        expect(
            $failed[
                'previous_error_message'
            ],
        )->toBe(
            'Fake WebSocket disconnected.',
        );

        expect(
            $failed[
                'exception_chain'
            ],
        )->toBe([
            [
                'class' => RealtimeTranslationException::class,

                'message' => 'Fake realtime translation failed.',
            ],
            [
                'class' => RuntimeException::class,

                'message' => 'Fake WebSocket disconnected.',
            ],
        ]);
    },
);

final class BenchmarkRealtimeFakePcm16AudioConverter implements Pcm16AudioConverter
{
    public function convert(
        string $audioPath,
        int $sampleRate,
        int $channels,
    ): string {
        return str_repeat(
            "\0",
            4800,
        );
    }
}

final class BenchmarkRealtimeFakeTranslationProvider implements RealtimeTranslationProvider
{
    private int $calls = 0;

    public function translate(
        iterable $pcm16Chunks,
        Language $sourceLanguage,
        Language $targetLanguage,
        ?Closure $onSourceTranscript = null,
        ?Closure $onTranslatedTranscript = null,
        ?Closure $onTranslatedAudio = null,
    ): RealtimeTranslationResult {
        $this->calls++;

        if ($this->calls === 2) {
            throw new RealtimeTranslationException(
                'Fake realtime translation failed.',
                previous: new RuntimeException(
                    'Fake WebSocket disconnected.',
                ),
            );
        }

        return new RealtimeTranslationResult(
            sourceTranscript: null,
            translatedTranscript: 'Am I free or not free?',
            translatedAudio: str_repeat(
                "\0",
                4800,
            ),

            firstSourceTranscriptMs: null,
            firstTranslatedTranscriptMs: 50.0,

            firstInputAudioSentMs: 10.0,
            lastInputAudioSentMs: 90.0,
            inputFinishedMs: 100.0,

            firstAudioPacketMs: 30.0,
            leadingSilenceMs: 0.0,
            rawPlaybackFirstAudibleAudioMs: 30.0,
            firstAudibleAudioAvailableMs: 40.0,

            sessionClosedMs: 120.0,

            firstAudioPacketRelativeToInputEndMs: -70.0,

            rawPlaybackFirstAudibleAudioRelativeToInputEndMs: -70.0,

            firstAudibleAudioAvailableRelativeToInputEndMs: -60.0,

            firstAudibleAudioAvailableRelativeToFirstInputMs: 30.0,

            firstAudibleAudioAvailableRelativeToLastInputMs: -50.0,

            inputAudioStreamDurationMs: 90.0,

            translatedAudioDurationMs: 100.0,
        );
    }
}
