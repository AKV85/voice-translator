<?php

namespace App\Services\Benchmark;

use App\Contracts\AudioDurationProbe;
use App\Contracts\StreamingSpeechToTextProvider;
use App\DTO\StreamingTranscriptionEvent;
use App\Enums\Language;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;
use Throwable;

class StreamingSpeechBenchmarkRunner
{
    public function __construct(
        private readonly AudioDurationProbe $audioDurationProbe,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public function run(
        StreamingSpeechToTextProvider $provider,
        string $profileName,
        string $providerName,
        string $modelName,
        string $datasetPath,
        int $chunkDurationMs,
        ?string $phraseId = null,
    ): array {
        if (! File::exists($datasetPath)) {
            throw new RuntimeException(
                "Speech benchmark dataset not found: {$datasetPath}"
            );
        }

        if ($chunkDurationMs <= 0) {
            throw new RuntimeException(
                'Streaming benchmark chunk duration must be greater than zero.'
            );
        }

        $dataset = json_decode(
            File::get($datasetPath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $phrases = $dataset['phrases'] ?? [];

        if (! is_array($phrases)) {
            throw new RuntimeException(
                'Speech benchmark dataset phrases are invalid.'
            );
        }

        if ($phraseId !== null) {
            $phrases = array_values(
                array_filter(
                    $phrases,
                    static fn (mixed $phrase): bool => is_array($phrase)
                        && ($phrase['id'] ?? null) === $phraseId,
                ),
            );

            if ($phrases === []) {
                throw new RuntimeException(
                    "Speech benchmark phrase not found: {$phraseId}"
                );
            }
        }

        $datasetDirectory = dirname($datasetPath);

        $startedAt = now();
        $results = [];

        foreach ($phrases as $phrase) {
            if (! is_array($phrase)) {
                continue;
            }

            $results[] = $this->runPhrase(
                provider: $provider,
                providerName: $providerName,
                modelName: $modelName,
                phrase: $phrase,
                datasetDirectory: $datasetDirectory,
                chunkDurationMs: $chunkDurationMs,
            );
        }

        return [
            'benchmark_version' => 1,
            'benchmark_type' => 'streaming',
            'dataset_version' => $dataset['version'] ?? null,
            'profile' => $profileName,
            'provider' => $providerName,
            'model' => $modelName,
            'phrase_filter' => $phraseId,
            'chunk_duration_ms' => $chunkDurationMs,
            'started_at' => $startedAt->toIso8601String(),
            'completed_at' => now()->toIso8601String(),
            'fixture_count' => count($results),
            'results' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $phrase
     * @return array<string, mixed>
     */
    private function runPhrase(
        StreamingSpeechToTextProvider $provider,
        string $providerName,
        string $modelName,
        array $phrase,
        string $datasetDirectory,
        int $chunkDurationMs,
    ): array {
        $audioPath = $datasetDirectory.'/'.$phrase['audio'];

        $result = [
            'phrase_id' => $phrase['id'],
            'language' => $phrase['language'],
            'category' => $phrase['category'],
            'expected' => $phrase['expected'],
            'actual' => null,
            'audio' => $phrase['audio'],
            'success' => false,
            'audio_duration_ms' => null,
            'preparation_duration_ms' => null,
            'chunk_duration_ms' => $chunkDurationMs,
            'effective_chunk_duration_ms' => null,
            'chunk_size_bytes' => null,
            'chunk_count' => null,
            'time_to_first_transcript_ms' => null,
            'time_to_final_transcript_ms' => null,
            'finalization_latency_ms' => null,
            'total_duration_ms' => null,
            'event_count' => 0,
            'events' => [],
            'error' => null,
            'provider' => $providerName,
            'model' => $modelName,
            'recorded_at' => now()->toIso8601String(),
        ];

        if (! File::exists($audioPath)) {
            $result['error'] = 'Audio fixture not found.';

            return $result;
        }

        $preparationStartedAt = hrtime(true);

        $streamingStartedAt = null;
        $audioCompletedAt = null;
        $events = [];

        try {
            $audio = File::get($audioPath);

            $audioDurationMs = $this->audioDurationProbe
                ->durationMs($audioPath);

            $plannedChunkCount = max(
                1,
                (int) ceil(
                    $audioDurationMs / $chunkDurationMs
                ),
            );

            $audioSizeBytes = strlen($audio);

            $chunkSizeBytes = max(
                1,
                (int) ceil(
                    $audioSizeBytes / $plannedChunkCount
                ),
            );

            $chunkCount = max(
                1,
                (int) ceil(
                    $audioSizeBytes / $chunkSizeBytes
                ),
            );

            $effectiveChunkDurationMs =
                $audioDurationMs / $chunkCount;

            $result['audio_duration_ms'] = round(
                $audioDurationMs,
                3,
            );

            $result['chunk_size_bytes'] =
                $chunkSizeBytes;

            $result['chunk_count'] =
                $chunkCount;

            $result['effective_chunk_duration_ms'] = round(
                $effectiveChunkDurationMs,
                3,
            );

            $result['preparation_duration_ms'] = round(
                (
                    hrtime(true)
                    - $preparationStartedAt
                ) / 1_000_000,
                2,
            );

            /*
             * User-facing streaming measurements start here.
             *
             * File loading, ffprobe and chunk planning are benchmark
             * preparation and must not affect STT latency metrics.
             */
            $streamingStartedAt = hrtime(true);

            $chunks = $this->audioChunks(
                audio: $audio,
                chunkSizeBytes: $chunkSizeBytes,
                chunkDurationMs: $effectiveChunkDurationMs,
                onCompleted: function () use (
                    &$audioCompletedAt
                ): void {
                    $audioCompletedAt = hrtime(true);
                },
            );

            $streamingResult = $provider->transcribe(
                audioChunks: $chunks,
                mimeType: File::mimeType($audioPath)
                    ?: 'audio/webm',
                sourceLanguage: Language::from(
                    $phrase['language'],
                ),
                onTranscript: function (
                    string $text,
                    bool $isFinal,
                ) use (
                    &$events,
                    $streamingStartedAt
                ): void {
                    $events[] =
                        new StreamingTranscriptionEvent(
                            text: $text,
                            isFinal: $isFinal,
                            receivedAtMs: round(
                                (
                                    hrtime(true)
                                    - $streamingStartedAt
                                ) / 1_000_000,
                                2,
                            ),
                        );
                },
            );

            $completedAt = hrtime(true);

            $result['actual'] =
                $streamingResult->text;

            $result['success'] = true;

            $result['event_count'] =
                count($events);

            $result['events'] = array_map(
                fn (
                    StreamingTranscriptionEvent $event
                ): array => [
                    'text' => $event->text,
                    'is_final' => $event->isFinal,
                    'received_at_ms' => $event->receivedAtMs,
                ],
                $events,
            );

            $firstEvent = $events[0] ?? null;

            if ($firstEvent !== null) {
                $result[
                    'time_to_first_transcript_ms'
                ] = $firstEvent->receivedAtMs;
            }

            $finalEvents = array_values(
                array_filter(
                    $events,
                    fn (
                        StreamingTranscriptionEvent $event
                    ): bool => $event->isFinal,
                ),
            );

            $finalEvent = $finalEvents !== []
                ? $finalEvents[
                    array_key_last($finalEvents)
                ]
                : null;

            if ($finalEvent !== null) {
                $result[
                    'time_to_final_transcript_ms'
                ] = $finalEvent->receivedAtMs;
            }

            if (
                $audioCompletedAt !== null
                && $finalEvent !== null
            ) {
                $audioCompletedMs =
                    (
                        $audioCompletedAt
                        - $streamingStartedAt
                    ) / 1_000_000;

                $result[
                    'finalization_latency_ms'
                ] = round(
                    max(
                        0,
                        $finalEvent->receivedAtMs
                        - $audioCompletedMs,
                    ),
                    2,
                );
            }

            $result['total_duration_ms'] = round(
                (
                    $completedAt
                    - $streamingStartedAt
                ) / 1_000_000,
                2,
            );
        } catch (Throwable $exception) {
            $result['error'] = $exception::class;

            if ($streamingStartedAt !== null) {
                $result['total_duration_ms'] = round(
                    (
                        hrtime(true)
                        - $streamingStartedAt
                    ) / 1_000_000,
                    2,
                );
            } else {
                $result['preparation_duration_ms'] = round(
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
     * @param  callable(): void  $onCompleted
     * @return iterable<int, string>
     */
    private function audioChunks(
        string $audio,
        int $chunkSizeBytes,
        float $chunkDurationMs,
        callable $onCompleted,
    ): iterable {
        if ($chunkSizeBytes <= 0) {
            throw new RuntimeException(
                'Streaming benchmark chunk size must be greater than zero.'
            );
        }

        if ($chunkDurationMs <= 0) {
            throw new RuntimeException(
                'Streaming benchmark chunk duration must be greater than zero.'
            );
        }

        $length = strlen($audio);
        $pacingStartedAt = hrtime(true);
        $chunkIndex = 0;

        for (
            $offset = 0;
            $offset < $length;
            $offset += $chunkSizeBytes
        ) {
            $targetElapsedNanoseconds = (int) round(
                (
                    ($chunkIndex + 1)
                    * $chunkDurationMs
                ) * 1_000_000
            );

            $elapsedNanoseconds =
                hrtime(true) - $pacingStartedAt;

            $sleepNanoseconds =
                $targetElapsedNanoseconds
                - $elapsedNanoseconds;

            if ($sleepNanoseconds > 0) {
                usleep(
                    (int) ceil(
                        $sleepNanoseconds / 1000
                    )
                );
            }

            yield substr(
                $audio,
                $offset,
                $chunkSizeBytes,
            );

            $chunkIndex++;
        }

        $onCompleted();
    }
}
