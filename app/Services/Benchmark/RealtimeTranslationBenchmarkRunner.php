<?php

namespace App\Services\Benchmark;

use App\Contracts\Pcm16AudioConverter;
use App\Contracts\RealtimeTranslationProvider;
use App\Enums\Language;
use Closure;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;
use Throwable;

final readonly class RealtimeTranslationBenchmarkRunner
{
    private const SAMPLE_RATE = 24000;

    private const CHANNELS = 1;

    private const BYTES_PER_SAMPLE = 2;

    public function __construct(
        private Pcm16AudioConverter $audioConverter,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public function run(
        RealtimeTranslationProvider $provider,
        string $profileName,
        string $providerName,
        string $modelName,
        string $speechDatasetPath,
        string $translationDatasetPath,
        int $chunkDurationMs,
        int $runsPerFixture,
        ?string $languagePair = null,
        ?string $phraseId = null,
        ?Closure $onRunStarted = null,
        ?Closure $onRunCompleted = null,
    ): array {
        if (! File::exists($speechDatasetPath)) {
            throw new RuntimeException(
                "Speech benchmark dataset not found: {$speechDatasetPath}",
            );
        }

        if (! File::exists($translationDatasetPath)) {
            throw new RuntimeException(
                "Translation benchmark dataset not found: {$translationDatasetPath}",
            );
        }

        if ($chunkDurationMs <= 0) {
            throw new RuntimeException(
                'Realtime translation chunk duration must be greater than zero.',
            );
        }

        if ($runsPerFixture <= 0) {
            throw new RuntimeException(
                'Realtime translation runs per fixture must be greater than zero.',
            );
        }

        $speechDataset = json_decode(
            File::get($speechDatasetPath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $translationDataset = json_decode(
            File::get($translationDatasetPath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (! is_array($speechDataset)) {
            throw new RuntimeException(
                'Speech benchmark dataset is invalid.',
            );
        }

        if (! is_array($translationDataset)) {
            throw new RuntimeException(
                'Translation benchmark dataset is invalid.',
            );
        }

        $speechPhrases =
            $speechDataset['phrases']
            ?? null;

        $translationPhrases =
            $translationDataset['phrases']
            ?? null;

        if (! is_array($speechPhrases)) {
            throw new RuntimeException(
                'Speech benchmark dataset phrases are invalid.',
            );
        }

        if (! is_array($translationPhrases)) {
            throw new RuntimeException(
                'Translation benchmark dataset phrases are invalid.',
            );
        }

        $fixtures = $this->pairFixtures(
            speechPhrases: $speechPhrases,
            translationPhrases: $translationPhrases,
        );

        if ($languagePair !== null) {
            $fixtures = array_values(
                array_filter(
                    $fixtures,
                    static fn (array $fixture): bool => $fixture['language_pair']
                        === $languagePair,
                ),
            );
        }

        if ($phraseId !== null) {
            $fixtures = array_values(
                array_filter(
                    $fixtures,
                    static fn (array $fixture): bool => $fixture['speech']['id']
                        === $phraseId,
                ),
            );

            if ($fixtures === []) {
                throw new RuntimeException(
                    "Realtime translation benchmark phrase not found: {$phraseId}",
                );
            }
        }

        if ($fixtures === []) {
            throw new RuntimeException(
                'No realtime translation benchmark fixtures matched the requested filters.',
            );
        }

        $speechDatasetDirectory =
            dirname($speechDatasetPath);

        $startedAt = now();

        $results = [];

        $fixtureCount =
            count($fixtures);

        foreach ($fixtures as $fixtureOffset => $fixture) {
            $results[] = $this->runFixture(
                provider: $provider,
                providerName: $providerName,
                modelName: $modelName,
                speechPhrase: $fixture['speech'],
                translationPhrase: $fixture['translation'],
                speechDatasetDirectory: $speechDatasetDirectory,
                chunkDurationMs: $chunkDurationMs,
                runsPerFixture: $runsPerFixture,
                fixtureIndex: $fixtureOffset + 1,
                fixtureCount: $fixtureCount,
                onRunStarted: $onRunStarted,
                onRunCompleted: $onRunCompleted,
            );
        }

        return [
            'benchmark_version' => 1,

            'benchmark_type' => 'realtime_translation',

            'speech_dataset_version' => $speechDataset['version']
                ?? null,

            'translation_dataset_version' => $translationDataset['version']
                ?? null,

            'profile' => $profileName,

            'provider' => $providerName,

            'model' => $modelName,

            'language_pair_filter' => $languagePair,

            'phrase_filter' => $phraseId,

            'chunk_duration_ms' => $chunkDurationMs,

            'runs_per_fixture' => $runsPerFixture,

            'audio_input' => [
                'format' => 'pcm16',
                'sample_rate_hz' => self::SAMPLE_RATE,
                'channels' => self::CHANNELS,
            ],

            'started_at' => $startedAt->toIso8601String(),

            'completed_at' => now()->toIso8601String(),

            'fixture_count' => count($results),

            'run_count' => count($results)
                * $runsPerFixture,

            'results' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $speechPhrase
     * @param  array<string, mixed>  $translationPhrase
     * @return array<string, mixed>
     */
    private function runFixture(
        RealtimeTranslationProvider $provider,
        string $providerName,
        string $modelName,
        array $speechPhrase,
        array $translationPhrase,
        string $speechDatasetDirectory,
        int $chunkDurationMs,
        int $runsPerFixture,
        int $fixtureIndex,
        int $fixtureCount,
        ?Closure $onRunStarted,
        ?Closure $onRunCompleted,
    ): array {
        $audioRelativePath =
            $speechPhrase['audio']
            ?? null;

        if (! is_string($audioRelativePath)) {
            throw new RuntimeException(
                'Speech benchmark audio path is invalid.',
            );
        }

        $phraseId =
            $speechPhrase['id']
            ?? null;

        if (
            ! is_string($phraseId)
            || $phraseId === ''
        ) {
            throw new RuntimeException(
                'Speech benchmark phrase ID is invalid.',
            );
        }

        $audioPath =
            $speechDatasetDirectory
            .'/'
            .$audioRelativePath;

        $runs = [];

        for (
            $runNumber = 1;
            $runNumber <= $runsPerFixture;
            $runNumber++
        ) {
            $progressBase = [
                'fixture_index' => $fixtureIndex,

                'fixture_count' => $fixtureCount,

                'phrase_id' => $phraseId,

                'run_number' => $runNumber,

                'runs_per_fixture' => $runsPerFixture,
            ];

            if ($onRunStarted !== null) {
                $onRunStarted(
                    $progressBase,
                );
            }

            $runStartedAt =
                hrtime(true);

            $runResult =
                $this->runOnce(
                    provider: $provider,
                    providerName: $providerName,
                    modelName: $modelName,
                    speechPhrase: $speechPhrase,
                    translationPhrase: $translationPhrase,
                    audioPath: $audioPath,
                    chunkDurationMs: $chunkDurationMs,
                    runNumber: $runNumber,
                );

            $wallDurationMs =
                (
                    hrtime(true)
                    - $runStartedAt
                ) / 1_000_000;

            $runs[] =
                $runResult;

            if ($onRunCompleted !== null) {
                $onRunCompleted(
                    [
                        ...$progressBase,

                        'wall_duration_ms' => $wallDurationMs,

                        'result' => $runResult,
                    ],
                );
            }
        }

        return [
            'phrase_id' => $speechPhrase['id'],

            'translation_fixture_id' => $translationPhrase['id'],

            'language_pair' => $translationPhrase[
                    'source_language'
                ]
                .'-'
                .$translationPhrase[
                    'target_language'
                ],

            'source_language' => $translationPhrase[
                    'source_language'
                ],

            'target_language' => $translationPhrase[
                    'target_language'
                ],

            'speech_category' => $speechPhrase['category']
                ?? null,

            'translation_category' => $translationPhrase['category']
                ?? null,

            'source' => $translationPhrase['source'],

            'reference' => $translationPhrase['reference'],

            'critical_elements' => $translationPhrase[
                    'critical_elements'
                ] ?? [],

            'audio' => $audioRelativePath,

            'runs' => $runs,
        ];
    }

    /**
     * @param  array<string, mixed>  $speechPhrase
     * @param  array<string, mixed>  $translationPhrase
     * @return array<string, mixed>
     */
    private function runOnce(
        RealtimeTranslationProvider $provider,
        string $providerName,
        string $modelName,
        array $speechPhrase,
        array $translationPhrase,
        string $audioPath,
        int $chunkDurationMs,
        int $runNumber,
    ): array {
        $result = [
            'run' => $runNumber,

            'success' => false,

            'source_transcript' => null,

            'translated_text' => null,

            'pcm_audio_duration_ms' => null,

            'preparation_duration_ms' => null,

            'chunk_duration_ms' => $chunkDurationMs,

            'chunk_size_bytes' => null,

            'chunk_count' => null,

            'first_source_transcript_ms' => null,

            'time_to_first_translation_text_ms' => null,

            'first_input_audio_sent_ms' => null,

            'last_input_audio_sent_ms' => null,

            'input_finished_ms' => null,

            'input_audio_stream_duration_ms' => null,

            'time_to_first_audio_packet_ms' => null,

            'leading_silence_ms' => null,

            'raw_playback_first_audible_audio_ms' => null,

            'first_audible_audio_available_ms' => null,

            'first_audio_packet_relative_to_input_end_ms' => null,

            'raw_playback_first_audible_audio_relative_to_input_end_ms' => null,

            'first_audible_audio_available_relative_to_input_end_ms' => null,

            'first_audible_audio_available_relative_to_first_input_ms' => null,

            'first_audible_audio_available_relative_to_last_input_ms' => null,

            'translated_audio_duration_ms' => null,

            'translated_audio_bytes' => null,

            'session_closed_ms' => null,

            'total_duration_ms' => null,

            'error' => null,

            'error_message' => null,

            'previous_error' => null,

            'previous_error_message' => null,

            'exception_chain' => [],

            'provider' => $providerName,

            'model' => $modelName,

            'recorded_at' => now()->toIso8601String(),
        ];

        if (! File::exists($audioPath)) {
            $result['error'] =
                'Audio fixture not found.';

            $result['error_message'] =
                'Audio fixture not found.';

            return $result;
        }

        $preparationStartedAt =
            hrtime(true);

        $benchmarkStartedAt = null;

        try {
            $pcm =
                $this
                    ->audioConverter
                    ->convert(
                        audioPath: $audioPath,

                        sampleRate: self::SAMPLE_RATE,

                        channels: self::CHANNELS,
                    );

            $pcmAudioDurationMs =
                $this->pcmDurationMs(
                    $pcm,
                );

            $chunkSizeBytes =
                $this->chunkSizeBytes(
                    $chunkDurationMs,
                );

            $chunkCount = max(
                1,
                (int) ceil(
                    strlen($pcm)
                    / $chunkSizeBytes,
                ),
            );

            $result[
                'pcm_audio_duration_ms'
            ] = round(
                $pcmAudioDurationMs,
                3,
            );

            $result[
                'chunk_size_bytes'
            ] = $chunkSizeBytes;

            $result[
                'chunk_count'
            ] = $chunkCount;

            $result[
                'preparation_duration_ms'
            ] = round(
                (
                    hrtime(true)
                    - $preparationStartedAt
                ) / 1_000_000,
                2,
            );

            /*
             * Audio conversion is benchmark preparation.
             *
             * Provider timing begins immediately before
             * the realtime translation call.
             */
            $benchmarkStartedAt =
                hrtime(true);

            $translation =
                $provider->translate(
                    pcm16Chunks: $this->audioChunks(
                        pcm: $pcm,
                        chunkSizeBytes: $chunkSizeBytes,
                    ),

                    sourceLanguage: Language::from(
                        $translationPhrase[
                            'source_language'
                        ],
                    ),

                    targetLanguage: Language::from(
                        $translationPhrase[
                            'target_language'
                        ],
                    ),
                );

            $completedAt =
                hrtime(true);

            $result['success'] =
                true;

            $result['source_transcript'] =
                $translation
                    ->sourceTranscript;

            $result['translated_text'] =
                $translation
                    ->translatedTranscript;

            $result[
                'first_source_transcript_ms'
            ] =
                $translation
                    ->firstSourceTranscriptMs;

            $result[
                'time_to_first_translation_text_ms'
            ] =
                $translation
                    ->firstTranslatedTranscriptMs;

            $result[
                'first_input_audio_sent_ms'
            ] =
                $translation
                    ->firstInputAudioSentMs;

            $result[
                'last_input_audio_sent_ms'
            ] =
                $translation
                    ->lastInputAudioSentMs;

            $result[
                'input_finished_ms'
            ] =
                $translation
                    ->inputFinishedMs;

            $result[
                'input_audio_stream_duration_ms'
            ] =
                $translation
                    ->inputAudioStreamDurationMs;

            $result[
                'time_to_first_audio_packet_ms'
            ] =
                $translation
                    ->firstAudioPacketMs;

            $result[
                'leading_silence_ms'
            ] =
                $translation
                    ->leadingSilenceMs;

            $result[
                'raw_playback_first_audible_audio_ms'
            ] =
                $translation
                    ->rawPlaybackFirstAudibleAudioMs;

            $result[
                'first_audible_audio_available_ms'
            ] =
                $translation
                    ->firstAudibleAudioAvailableMs;

            $result[
                'first_audio_packet_relative_to_input_end_ms'
            ] =
                $translation
                    ->firstAudioPacketRelativeToInputEndMs;

            $result[
                'raw_playback_first_audible_audio_relative_to_input_end_ms'
            ] =
                $translation
                    ->rawPlaybackFirstAudibleAudioRelativeToInputEndMs;

            $result[
                'first_audible_audio_available_relative_to_input_end_ms'
            ] =
                $translation
                    ->firstAudibleAudioAvailableRelativeToInputEndMs;

            $result[
                'first_audible_audio_available_relative_to_first_input_ms'
            ] =
                $translation
                    ->firstAudibleAudioAvailableRelativeToFirstInputMs;

            $result[
                'first_audible_audio_available_relative_to_last_input_ms'
            ] =
                $translation
                    ->firstAudibleAudioAvailableRelativeToLastInputMs;

            $result[
                'translated_audio_duration_ms'
            ] =
                $translation
                    ->translatedAudioDurationMs;

            $result[
                'translated_audio_bytes'
            ] =
                strlen(
                    $translation
                        ->translatedAudio,
                );

            $result[
                'session_closed_ms'
            ] =
                $translation
                    ->sessionClosedMs;

            $result[
                'total_duration_ms'
            ] = round(
                (
                    $completedAt
                    - $benchmarkStartedAt
                ) / 1_000_000,
                2,
            );
        } catch (Throwable $exception) {
            $previous =
                $exception->getPrevious();

            $result['error'] =
                $exception::class;

            $result['error_message'] =
                $exception->getMessage();

            $result['previous_error'] =
                $previous !== null
                    ? $previous::class
                    : null;

            $result[
                'previous_error_message'
            ] =
                $previous?->getMessage();

            $result['exception_chain'] =
                $this->exceptionChain(
                    $exception,
                );

            if ($benchmarkStartedAt !== null) {
                $result[
                    'total_duration_ms'
                ] = round(
                    (
                        hrtime(true)
                        - $benchmarkStartedAt
                    ) / 1_000_000,
                    2,
                );
            } else {
                $result[
                    'preparation_duration_ms'
                ] = round(
                    (
                        hrtime(true)
                        - $preparationStartedAt
                    ) / 1_000_000,
                    2,
                );
            }
        }

        return $result;
    }

    /**
     * @return list<array{
     *     class: string,
     *     message: string
     * }>
     */
    private function exceptionChain(
        Throwable $exception,
    ): array {
        $chain = [];

        $current =
            $exception;

        while ($current !== null) {
            $chain[] = [
                'class' => $current::class,

                'message' => $current->getMessage(),
            ];

            $current =
                $current->getPrevious();
        }

        return $chain;
    }

    /**
     * @param  array<int, mixed>  $speechPhrases
     * @param  array<int, mixed>  $translationPhrases
     * @return array<int, array{
     *     speech: array<string, mixed>,
     *     translation: array<string, mixed>,
     *     language_pair: string
     * }>
     */
    private function pairFixtures(
        array $speechPhrases,
        array $translationPhrases,
    ): array {
        $translationBySource = [];

        foreach ($translationPhrases as $phrase) {
            if (! is_array($phrase)) {
                continue;
            }

            $sourceLanguage =
                $phrase[
                    'source_language'
                ] ?? null;

            $source =
                $phrase['source']
                ?? null;

            if (
                ! is_string($sourceLanguage)
                || ! is_string($source)
            ) {
                continue;
            }

            $key =
                $sourceLanguage
                ."\0"
                .$source;

            $translationBySource[$key] =
                $phrase;
        }

        $fixtures = [];

        foreach ($speechPhrases as $speechPhrase) {
            if (! is_array($speechPhrase)) {
                continue;
            }

            $language =
                $speechPhrase['language']
                ?? null;

            $expected =
                $speechPhrase['expected']
                ?? null;

            $phraseId =
                $speechPhrase['id']
                ?? 'unknown';

            if (
                ! is_string($language)
                || ! is_string($expected)
            ) {
                throw new RuntimeException(
                    "Invalid speech benchmark fixture: {$phraseId}",
                );
            }

            $key =
                $language
                ."\0"
                .$expected;

            $translationPhrase =
                $translationBySource[$key]
                ?? null;

            if (! is_array($translationPhrase)) {
                throw new RuntimeException(
                    "Translation fixture not found for speech phrase: {$phraseId}",
                );
            }

            $sourceLanguage =
                $translationPhrase[
                    'source_language'
                ] ?? null;

            $targetLanguage =
                $translationPhrase[
                    'target_language'
                ] ?? null;

            if (
                ! is_string($sourceLanguage)
                || ! is_string($targetLanguage)
            ) {
                throw new RuntimeException(
                    "Invalid translation fixture for speech phrase: {$phraseId}",
                );
            }

            $fixtures[] = [
                'speech' => $speechPhrase,

                'translation' => $translationPhrase,

                'language_pair' => $sourceLanguage
                    .'-'
                    .$targetLanguage,
            ];
        }

        return $fixtures;
    }

    /**
     * @return iterable<int, string>
     */
    private function audioChunks(
        string $pcm,
        int $chunkSizeBytes,
    ): iterable {
        $length =
            strlen($pcm);

        for (
            $offset = 0;
            $offset < $length;
            $offset += $chunkSizeBytes
        ) {
            yield substr(
                $pcm,
                $offset,
                $chunkSizeBytes,
            );
        }
    }

    private function chunkSizeBytes(
        int $chunkDurationMs,
    ): int {
        $bytesPerSecond =
            self::SAMPLE_RATE
            * self::CHANNELS
            * self::BYTES_PER_SAMPLE;

        $chunkSizeBytes =
            (int) round(
                $bytesPerSecond
                * ($chunkDurationMs / 1000),
            );

        if ($chunkSizeBytes <= 0) {
            throw new RuntimeException(
                'Realtime translation chunk size must be greater than zero.',
            );
        }

        if (
            $chunkSizeBytes
            % self::BYTES_PER_SAMPLE !== 0
        ) {
            $chunkSizeBytes--;
        }

        if ($chunkSizeBytes <= 0) {
            throw new RuntimeException(
                'Realtime translation chunk size is invalid.',
            );
        }

        return $chunkSizeBytes;
    }

    private function pcmDurationMs(
        string $pcm,
    ): float {
        $bytesPerSecond =
            self::SAMPLE_RATE
            * self::CHANNELS
            * self::BYTES_PER_SAMPLE;

        return (
            strlen($pcm)
            / $bytesPerSecond
        ) * 1000;
    }
}
