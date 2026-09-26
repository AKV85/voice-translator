<?php

namespace App\Services\Benchmark;

use App\Contracts\AudioDurationProbe;
use App\Contracts\StreamingSpeechToTextProvider;
use App\Contracts\StreamingTextToSpeechProvider;
use App\Contracts\TranslationProvider;
use App\DTO\StreamingTranscriptionEvent;
use App\Enums\Language;
use App\Exceptions\SpeechRecognitionException;
use Closure;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;
use Throwable;

final readonly class PipelineTranslationBenchmarkRunner
{
    private const TTS_SAMPLE_RATE = 24000;

    private const TTS_CHANNELS = 1;

    private const TTS_BYTES_PER_SAMPLE = 2;

    private const AUDIBLE_FRAME_DURATION_MS = 10;

    private const AUDIBLE_RMS_THRESHOLD = 328.0;

    public function __construct(
        private AudioDurationProbe $audioDurationProbe,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public function run(
        StreamingSpeechToTextProvider $speechToTextProvider,
        TranslationProvider $translationProvider,
        StreamingTextToSpeechProvider $textToSpeechProvider,
        string $profileName,
        string $speechProviderName,
        string $speechModelName,
        string $translationProviderName,
        string $translationModelName,
        string $textToSpeechProviderName,
        string $textToSpeechModelName,
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
                'Pipeline benchmark chunk duration must be greater than zero.',
            );
        }

        if ($runsPerFixture <= 0) {
            throw new RuntimeException(
                'Pipeline benchmark runs per fixture must be greater than zero.',
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
                    "Pipeline translation benchmark phrase not found: {$phraseId}",
                );
            }
        }

        if ($fixtures === []) {
            throw new RuntimeException(
                'No pipeline translation benchmark fixtures matched the requested filters.',
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
                speechToTextProvider: $speechToTextProvider,
                translationProvider: $translationProvider,
                textToSpeechProvider: $textToSpeechProvider,
                speechProviderName: $speechProviderName,
                speechModelName: $speechModelName,
                translationProviderName: $translationProviderName,
                translationModelName: $translationModelName,
                textToSpeechProviderName: $textToSpeechProviderName,
                textToSpeechModelName: $textToSpeechModelName,
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

            'benchmark_type' => 'pipeline_translation',

            'speech_dataset_version' => $speechDataset['version']
                ?? null,

            'translation_dataset_version' => $translationDataset['version']
                ?? null,

            'profile' => $profileName,

            'pipeline' => [
                'speech_to_text' => [
                    'provider' => $speechProviderName,
                    'model' => $speechModelName,
                ],

                'translation' => [
                    'provider' => $translationProviderName,
                    'model' => $translationModelName,
                ],

                'text_to_speech' => [
                    'provider' => $textToSpeechProviderName,
                    'model' => $textToSpeechModelName,
                ],
            ],

            'language_pair_filter' => $languagePair,

            'phrase_filter' => $phraseId,

            'chunk_duration_ms' => $chunkDurationMs,

            'runs_per_fixture' => $runsPerFixture,

            'tts_audio_output' => [
                'format' => 'pcm16',
                'sample_rate_hz' => self::TTS_SAMPLE_RATE,
                'channels' => self::TTS_CHANNELS,
                'bytes_per_sample' => self::TTS_BYTES_PER_SAMPLE,
            ],

            'audibility_detection' => [
                'frame_duration_ms' => self::AUDIBLE_FRAME_DURATION_MS,

                'rms_threshold' => self::AUDIBLE_RMS_THRESHOLD,
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
        StreamingSpeechToTextProvider $speechToTextProvider,
        TranslationProvider $translationProvider,
        StreamingTextToSpeechProvider $textToSpeechProvider,
        string $speechProviderName,
        string $speechModelName,
        string $translationProviderName,
        string $translationModelName,
        string $textToSpeechProviderName,
        string $textToSpeechModelName,
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
                    speechToTextProvider: $speechToTextProvider,

                    translationProvider: $translationProvider,

                    textToSpeechProvider: $textToSpeechProvider,

                    speechProviderName: $speechProviderName,

                    speechModelName: $speechModelName,

                    translationProviderName: $translationProviderName,

                    translationModelName: $translationModelName,

                    textToSpeechProviderName: $textToSpeechProviderName,

                    textToSpeechModelName: $textToSpeechModelName,

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
        StreamingSpeechToTextProvider $speechToTextProvider,
        TranslationProvider $translationProvider,
        StreamingTextToSpeechProvider $textToSpeechProvider,
        string $speechProviderName,
        string $speechModelName,
        string $translationProviderName,
        string $translationModelName,
        string $textToSpeechProviderName,
        string $textToSpeechModelName,
        array $speechPhrase,
        array $translationPhrase,
        string $audioPath,
        int $chunkDurationMs,
        int $runNumber,
    ): array {
        $result = [
            'run' => $runNumber,

            'success' => false,

            'stt_success' => false,

            'translation_success' => false,

            'tts_success' => false,

            'audio_success' => false,

            'source_transcript' => null,

            'translated_text' => null,

            'source_audio_duration_ms' => null,

            'preparation_duration_ms' => null,

            'chunk_duration_ms' => $chunkDurationMs,

            'effective_chunk_duration_ms' => null,

            'chunk_size_bytes' => null,

            'chunk_count' => null,

            'first_input_audio_provided_ms' => null,

            'last_input_audio_provided_ms' => null,

            'input_finished_ms' => null,

            'input_audio_stream_duration_ms' => null,

            'first_stt_transcript_ms' => null,

            'final_stt_transcript_ms' => null,

            'stt_completed_ms' => null,

            'stt_finalization_latency_ms' => null,

            'stt_completed_relative_to_input_end_ms' => null,

            'translation_started_ms' => null,

            'translation_completed_ms' => null,

            'translation_latency_ms' => null,

            'translation_completed_relative_to_input_end_ms' => null,

            'tts_started_ms' => null,

            'first_tts_audio_chunk_available_ms' => null,

            'tts_first_audio_chunk_latency_ms' => null,

            'leading_silence_ms' => null,

            'raw_playback_first_audible_audio_ms' => null,

            'first_audible_audio_available_ms' => null,

            'tts_first_audible_available_latency_ms' => null,

            'tts_completed_ms' => null,

            'tts_duration_ms' => null,

            'first_tts_audio_chunk_relative_to_input_end_ms' => null,

            'raw_playback_first_audible_audio_relative_to_input_end_ms' => null,

            'first_audible_audio_available_relative_to_input_end_ms' => null,

            'first_audible_audio_available_relative_to_first_input_ms' => null,

            'tts_completed_relative_to_input_end_ms' => null,

            'translated_audio_duration_ms' => null,

            'translated_audio_bytes' => null,

            'stt_event_count' => 0,

            'stt_events' => [],

            'total_duration_ms' => null,

            'failed_stage' => null,

            'error' => null,

            'error_message' => null,

            'previous_error' => null,

            'previous_error_message' => null,

            'exception_chain' => [],

            'speech_provider' => $speechProviderName,

            'speech_model' => $speechModelName,

            'translation_provider' => $translationProviderName,

            'translation_model' => $translationModelName,

            'tts_provider' => $textToSpeechProviderName,

            'tts_model' => $textToSpeechModelName,

            'recorded_at' => now()->toIso8601String(),
        ];

        if (! File::exists($audioPath)) {
            $result['error'] =
                'Audio fixture not found.';

            $result['error_message'] =
                'Audio fixture not found.';

            $result['failed_stage'] =
                'preparation';

            return $result;
        }

        $preparationStartedAt =
            hrtime(true);

        $benchmarkStartedAt = null;

        $failedStage =
            'preparation';

        try {
            $audio =
                File::get($audioPath);

            $audioDurationMs =
                $this
                    ->audioDurationProbe
                    ->durationMs(
                        $audioPath,
                    );

            $plannedChunkCount = max(
                1,
                (int) ceil(
                    $audioDurationMs
                    / $chunkDurationMs,
                ),
            );

            $audioSizeBytes =
                strlen($audio);

            $chunkSizeBytes = max(
                1,
                (int) ceil(
                    $audioSizeBytes
                    / $plannedChunkCount,
                ),
            );

            $chunkCount = max(
                1,
                (int) ceil(
                    $audioSizeBytes
                    / $chunkSizeBytes,
                ),
            );

            $effectiveChunkDurationMs =
                $audioDurationMs
                / $chunkCount;

            $mimeType =
                File::mimeType($audioPath)
                ?: 'audio/webm';

            $result[
                'source_audio_duration_ms'
            ] = round(
                $audioDurationMs,
                3,
            );

            $result[
                'chunk_size_bytes'
            ] =
                $chunkSizeBytes;

            $result[
                'chunk_count'
            ] =
                $chunkCount;

            $result[
                'effective_chunk_duration_ms'
            ] = round(
                $effectiveChunkDurationMs,
                3,
            );

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
             * Preparation ends here.
             *
             * User-facing pipeline timing begins immediately
             * before the streaming STT call.
             */
            $benchmarkStartedAt =
                hrtime(true);

            $firstInputAudioProvidedAt =
                null;

            $lastInputAudioProvidedAt =
                null;

            $inputFinishedAt =
                null;

            $sttEvents = [];

            $failedStage =
                'stt';

            $sourceLanguage =
                Language::from(
                    $translationPhrase[
                        'source_language'
                    ],
                );

            $targetLanguage =
                Language::from(
                    $translationPhrase[
                        'target_language'
                    ],
                );

            $streamingResult =
                $speechToTextProvider
                    ->transcribe(
                        audioChunks: $this->audioChunks(
                            audio: $audio,

                            chunkSizeBytes: $chunkSizeBytes,

                            chunkDurationMs: $effectiveChunkDurationMs,

                            onChunkProvided: function () use (
                                &$firstInputAudioProvidedAt,
                                &$lastInputAudioProvidedAt,
                            ): void {
                                $now =
                                    hrtime(true);

                                if (
                                    $firstInputAudioProvidedAt
                                    === null
                                ) {
                                    $firstInputAudioProvidedAt =
                                        $now;
                                }

                                $lastInputAudioProvidedAt =
                                    $now;
                            },

                            onCompleted: function () use (
                                &$inputFinishedAt,
                            ): void {
                                $inputFinishedAt =
                                    hrtime(true);
                            },
                        ),

                        mimeType: $mimeType,

                        sourceLanguage: $sourceLanguage,

                        onTranscript: function (
                            string $text,
                            bool $isFinal,
                        ) use (
                            &$sttEvents,
                            $benchmarkStartedAt,
                        ): void {
                            $sttEvents[] =
                                new StreamingTranscriptionEvent(
                                    text: $text,

                                    isFinal: $isFinal,

                                    receivedAtMs: round(
                                        (
                                            hrtime(true)
                                            - $benchmarkStartedAt
                                        ) / 1_000_000,
                                        2,
                                    ),
                                );
                        },
                    );

            $sttCompletedAt =
                hrtime(true);

            if ($inputFinishedAt === null) {
                throw new SpeechRecognitionException(
                    'Streaming speech-to-text provider did not consume the full input audio.',
                );
            }

            if (
                trim(
                    $streamingResult->text,
                ) === ''
            ) {
                throw new SpeechRecognitionException(
                    'Streaming speech-to-text provider returned an empty transcript.',
                );
            }

            $result['source_transcript'] =
                $streamingResult->text;

            $result['stt_success'] =
                true;

            $result['stt_event_count'] =
                count($sttEvents);

            $result['stt_events'] =
                array_map(
                    static fn (
                        StreamingTranscriptionEvent $event,
                    ): array => [
                        'text' => $event->text,

                        'is_final' => $event->isFinal,

                        'received_at_ms' => $event->receivedAtMs,
                    ],
                    $sttEvents,
                );

            if (
                $firstInputAudioProvidedAt
                !== null
            ) {
                $result[
                    'first_input_audio_provided_ms'
                ] = round(
                    $this->millisecondsBetween(
                        $benchmarkStartedAt,
                        $firstInputAudioProvidedAt,
                    ),
                    2,
                );
            }

            if (
                $lastInputAudioProvidedAt
                !== null
            ) {
                $result[
                    'last_input_audio_provided_ms'
                ] = round(
                    $this->millisecondsBetween(
                        $benchmarkStartedAt,
                        $lastInputAudioProvidedAt,
                    ),
                    2,
                );
            }

            $result[
                'input_finished_ms'
            ] = round(
                $this->millisecondsBetween(
                    $benchmarkStartedAt,
                    $inputFinishedAt,
                ),
                2,
            );

            if (
                $firstInputAudioProvidedAt
                !== null
            ) {
                $result[
                    'input_audio_stream_duration_ms'
                ] = round(
                    $this->millisecondsBetween(
                        $firstInputAudioProvidedAt,
                        $inputFinishedAt,
                    ),
                    2,
                );
            }

            $result[
                'stt_completed_ms'
            ] = round(
                $this->millisecondsBetween(
                    $benchmarkStartedAt,
                    $sttCompletedAt,
                ),
                2,
            );

            $result[
                'stt_completed_relative_to_input_end_ms'
            ] = round(
                $this->millisecondsBetween(
                    $inputFinishedAt,
                    $sttCompletedAt,
                ),
                2,
            );

            $firstSttEvent =
                $sttEvents[0]
                ?? null;

            if ($firstSttEvent !== null) {
                $result[
                    'first_stt_transcript_ms'
                ] =
                    $firstSttEvent
                        ->receivedAtMs;
            }

            $finalSttEvents =
                array_values(
                    array_filter(
                        $sttEvents,
                        static fn (
                            StreamingTranscriptionEvent $event,
                        ): bool => $event->isFinal,
                    ),
                );

            $finalSttEvent =
                $finalSttEvents !== []
                    ? $finalSttEvents[
                        array_key_last(
                            $finalSttEvents,
                        )
                    ]
                    : null;

            if ($finalSttEvent !== null) {
                $result[
                    'final_stt_transcript_ms'
                ] =
                    $finalSttEvent
                        ->receivedAtMs;

                $result[
                    'stt_finalization_latency_ms'
                ] = round(
                    max(
                        0,
                        $finalSttEvent
                            ->receivedAtMs
                        - $result[
                            'input_finished_ms'
                        ],
                    ),
                    2,
                );
            }

            /*
             * Translation begins only after transcribe()
             * has fully returned.
             *
             * We intentionally do not start a synchronous
             * translation request inside the STT callback.
             */
            $failedStage =
                'translation';

            $translationStartedAt =
                hrtime(true);

            $result[
                'translation_started_ms'
            ] = round(
                $this->millisecondsBetween(
                    $benchmarkStartedAt,
                    $translationStartedAt,
                ),
                2,
            );

            $translation =
                $translationProvider
                    ->translate(
                        text: $streamingResult->text,

                        sourceLanguage: $sourceLanguage,

                        targetLanguage: $targetLanguage,
                    );

            $translationCompletedAt =
                hrtime(true);

            $result['translated_text'] =
                $translation->text;

            $result[
                'translation_success'
            ] =
                true;

            $result[
                'translation_completed_ms'
            ] = round(
                $this->millisecondsBetween(
                    $benchmarkStartedAt,
                    $translationCompletedAt,
                ),
                2,
            );

            $result[
                'translation_latency_ms'
            ] = round(
                $this->millisecondsBetween(
                    $translationStartedAt,
                    $translationCompletedAt,
                ),
                2,
            );

            $result[
                'translation_completed_relative_to_input_end_ms'
            ] = round(
                $this->millisecondsBetween(
                    $inputFinishedAt,
                    $translationCompletedAt,
                ),
                2,
            );

            /*
             * Streaming TTS.
             *
             * PCM chunks may have arbitrary network sizes,
             * so audibility detection buffers them into
             * fixed 10 ms PCM16 frames.
             */
            $failedStage =
                'tts';

            $audibilityDetector =
                new Pcm16AudibilityDetector(
                    sampleRate: self::TTS_SAMPLE_RATE,

                    frameDurationMs: self::AUDIBLE_FRAME_DURATION_MS,

                    rmsThreshold: self::AUDIBLE_RMS_THRESHOLD,
                );

            $ttsStartedAt =
                hrtime(true);

            $result[
                'tts_started_ms'
            ] = round(
                $this->millisecondsBetween(
                    $benchmarkStartedAt,
                    $ttsStartedAt,
                ),
                2,
            );

            $firstTtsAudioChunkAt =
                null;

            $firstAudibleAudioAvailableAt =
                null;

            $leadingSilenceMs =
                null;

            $speech =
                $textToSpeechProvider
                    ->synthesize(
                        text: $translation->text,

                        targetLanguage: $targetLanguage,

                        onAudioChunk: function (
                            string $chunk,
                        ) use (
                            $audibilityDetector,
                            &$firstTtsAudioChunkAt,
                            &$firstAudibleAudioAvailableAt,
                            &$leadingSilenceMs,
                        ): void {
                            $receivedAt =
                                hrtime(true);

                            if (
                                $firstTtsAudioChunkAt
                                === null
                            ) {
                                $firstTtsAudioChunkAt =
                                    $receivedAt;
                            }

                            if (
                                $firstAudibleAudioAvailableAt
                                !== null
                            ) {
                                return;
                            }

                            $audibleOffsetMs =
                                $audibilityDetector
                                    ->consume(
                                        $chunk,
                                    );

                            if (
                                $audibleOffsetMs
                                === null
                            ) {
                                return;
                            }

                            $leadingSilenceMs =
                                $audibleOffsetMs;

                            $firstAudibleAudioAvailableAt =
                                $receivedAt;
                        },
                    );

            $ttsCompletedAt =
                hrtime(true);

            $result['tts_success'] =
                true;

            $result[
                'translated_audio_bytes'
            ] =
                strlen(
                    $speech->audio,
                );

            $result[
                'translated_audio_duration_ms'
            ] = round(
                $this->pcmDurationMilliseconds(
                    $speech->audio,
                ),
                3,
            );

            $result[
                'tts_completed_ms'
            ] = round(
                $this->millisecondsBetween(
                    $benchmarkStartedAt,
                    $ttsCompletedAt,
                ),
                2,
            );

            $result[
                'tts_duration_ms'
            ] = round(
                $this->millisecondsBetween(
                    $ttsStartedAt,
                    $ttsCompletedAt,
                ),
                2,
            );

            $result[
                'tts_completed_relative_to_input_end_ms'
            ] = round(
                $this->millisecondsBetween(
                    $inputFinishedAt,
                    $ttsCompletedAt,
                ),
                2,
            );

            if (
                $firstTtsAudioChunkAt
                !== null
            ) {
                $firstTtsAudioChunkAvailableMs =
                    $this->millisecondsBetween(
                        $benchmarkStartedAt,
                        $firstTtsAudioChunkAt,
                    );

                $result[
                    'first_tts_audio_chunk_available_ms'
                ] = round(
                    $firstTtsAudioChunkAvailableMs,
                    2,
                );

                $result[
                    'tts_first_audio_chunk_latency_ms'
                ] = round(
                    $this->millisecondsBetween(
                        $ttsStartedAt,
                        $firstTtsAudioChunkAt,
                    ),
                    2,
                );

                $result[
                    'first_tts_audio_chunk_relative_to_input_end_ms'
                ] = round(
                    $this->millisecondsBetween(
                        $inputFinishedAt,
                        $firstTtsAudioChunkAt,
                    ),
                    2,
                );
            }

            if (
                $leadingSilenceMs
                !== null
            ) {
                $result[
                    'leading_silence_ms'
                ] = round(
                    $leadingSilenceMs,
                    3,
                );
            }

            if (
                $firstTtsAudioChunkAt
                !== null
                && $leadingSilenceMs
                !== null
            ) {
                $rawPlaybackFirstAudibleAudioMs =
                    $this->millisecondsBetween(
                        $benchmarkStartedAt,
                        $firstTtsAudioChunkAt,
                    )
                    + $leadingSilenceMs;

                $result[
                    'raw_playback_first_audible_audio_ms'
                ] = round(
                    $rawPlaybackFirstAudibleAudioMs,
                    2,
                );

                $inputFinishedMs =
                    $this->millisecondsBetween(
                        $benchmarkStartedAt,
                        $inputFinishedAt,
                    );

                $result[
                    'raw_playback_first_audible_audio_relative_to_input_end_ms'
                ] = round(
                    $rawPlaybackFirstAudibleAudioMs
                    - $inputFinishedMs,
                    2,
                );
            }

            if (
                $firstAudibleAudioAvailableAt
                !== null
            ) {
                $firstAudibleAudioAvailableMs =
                    $this->millisecondsBetween(
                        $benchmarkStartedAt,
                        $firstAudibleAudioAvailableAt,
                    );

                $result[
                    'first_audible_audio_available_ms'
                ] = round(
                    $firstAudibleAudioAvailableMs,
                    2,
                );

                $result[
                    'tts_first_audible_available_latency_ms'
                ] = round(
                    $this->millisecondsBetween(
                        $ttsStartedAt,
                        $firstAudibleAudioAvailableAt,
                    ),
                    2,
                );

                $result[
                    'first_audible_audio_available_relative_to_input_end_ms'
                ] = round(
                    $this->millisecondsBetween(
                        $inputFinishedAt,
                        $firstAudibleAudioAvailableAt,
                    ),
                    2,
                );

                if (
                    $firstInputAudioProvidedAt
                    !== null
                ) {
                    $result[
                        'first_audible_audio_available_relative_to_first_input_ms'
                    ] = round(
                        $this->millisecondsBetween(
                            $firstInputAudioProvidedAt,
                            $firstAudibleAudioAvailableAt,
                        ),
                        2,
                    );
                }

                $result['audio_success'] =
                    true;
            }

            $result['success'] =
                true;

            $result[
                'total_duration_ms'
            ] = round(
                $this->millisecondsBetween(
                    $benchmarkStartedAt,
                    $ttsCompletedAt,
                ),
                2,
            );
        } catch (Throwable $exception) {
            $previous =
                $exception->getPrevious();

            $result['failed_stage'] =
                $failedStage;

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
     * @param  callable(): void  $onChunkProvided
     * @param  callable(): void  $onCompleted
     * @return iterable<int, string>
     */
    private function audioChunks(
        string $audio,
        int $chunkSizeBytes,
        float $chunkDurationMs,
        callable $onChunkProvided,
        callable $onCompleted,
    ): iterable {
        if ($chunkSizeBytes <= 0) {
            throw new RuntimeException(
                'Pipeline benchmark chunk size must be greater than zero.',
            );
        }

        if ($chunkDurationMs <= 0) {
            throw new RuntimeException(
                'Pipeline benchmark chunk duration must be greater than zero.',
            );
        }

        $length =
            strlen($audio);

        $pacingStartedAt =
            hrtime(true);

        $chunkIndex =
            0;

        for (
            $offset = 0;
            $offset < $length;
            $offset += $chunkSizeBytes
        ) {
            $targetElapsedNanoseconds =
                (int) round(
                    (
                        ($chunkIndex + 1)
                        * $chunkDurationMs
                    )
                    * 1_000_000,
                );

            $elapsedNanoseconds =
                hrtime(true)
                - $pacingStartedAt;

            $sleepNanoseconds =
                $targetElapsedNanoseconds
                - $elapsedNanoseconds;

            if ($sleepNanoseconds > 0) {
                usleep(
                    (int) ceil(
                        $sleepNanoseconds
                        / 1000,
                    ),
                );
            }

            $onChunkProvided();

            yield substr(
                $audio,
                $offset,
                $chunkSizeBytes,
            );

            $chunkIndex++;
        }

        $onCompleted();
    }

    private function millisecondsBetween(
        int $startedAt,
        int $endedAt,
    ): float {
        return (
            $endedAt
            - $startedAt
        ) / 1_000_000;
    }

    private function pcmDurationMilliseconds(
        string $audio,
    ): float {
        return (
            strlen($audio)
            / (
                self::TTS_SAMPLE_RATE
                * self::TTS_CHANNELS
                * self::TTS_BYTES_PER_SAMPLE
            )
        ) * 1000;
    }
}
