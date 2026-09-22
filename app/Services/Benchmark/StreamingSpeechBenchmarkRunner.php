<?php

namespace App\Services\Benchmark;

use App\Contracts\StreamingSpeechToTextProvider;
use App\DTO\StreamingTranscriptionEvent;
use App\Enums\Language;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;
use Throwable;

class StreamingSpeechBenchmarkRunner
{
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
        int $chunkSizeBytes,
        int $chunkIntervalMs,
    ): array {
        if (! File::exists($datasetPath)) {
            throw new RuntimeException(
                "Speech benchmark dataset not found: {$datasetPath}"
            );
        }

        $dataset = json_decode(
            File::get($datasetPath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $phrases = $dataset['phrases'] ?? [];
        $datasetDirectory = dirname($datasetPath);

        $startedAt = now();
        $results = [];

        foreach ($phrases as $phrase) {
            $results[] = $this->runPhrase(
                provider: $provider,
                providerName: $providerName,
                modelName: $modelName,
                phrase: $phrase,
                datasetDirectory: $datasetDirectory,
                chunkSizeBytes: $chunkSizeBytes,
                chunkIntervalMs: $chunkIntervalMs,
            );
        }

        return [
            'benchmark_version' => 1,
            'benchmark_type' => 'streaming',
            'dataset_version' => $dataset['version'] ?? null,
            'profile' => $profileName,
            'provider' => $providerName,
            'model' => $modelName,
            'chunk_size_bytes' => $chunkSizeBytes,
            'chunk_interval_ms' => $chunkIntervalMs,
            'started_at' => $startedAt->toIso8601String(),
            'completed_at' => now()->toIso8601String(),
            'fixture_count' => count($phrases),
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
        int $chunkSizeBytes,
        int $chunkIntervalMs,
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

        $audio = File::get($audioPath);

        $startedAt = hrtime(true);
        $audioCompletedAt = null;
        $events = [];

        try {
            $chunks = $this->audioChunks(
                audio: $audio,
                chunkSizeBytes: $chunkSizeBytes,
                chunkIntervalMs: $chunkIntervalMs,
                onCompleted: function () use (&$audioCompletedAt): void {
                    $audioCompletedAt = hrtime(true);
                },
            );

            $streamingResult = $provider->transcribe(
                audioChunks: $chunks,
                mimeType: File::mimeType($audioPath) ?: 'audio/webm',
                sourceLanguage: Language::from(
                    $phrase['language'],
                ),
                onTranscript: function (
                    string $text,
                    bool $isFinal,
                ) use (&$events, $startedAt): void {
                    $events[] = new StreamingTranscriptionEvent(
                        text: $text,
                        isFinal: $isFinal,
                        receivedAtMs: round(
                            (hrtime(true) - $startedAt)
                            / 1_000_000,
                            2,
                        ),
                    );
                },
            );

            $completedAt = hrtime(true);

            $result['actual'] = $streamingResult->text;
            $result['success'] = true;
            $result['event_count'] = count($events);

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
                $result['time_to_first_transcript_ms'] =
                    $firstEvent->receivedAtMs;
            }

            $finalEvents = array_values(array_filter(
                $events,
                fn (
                    StreamingTranscriptionEvent $event
                ): bool => $event->isFinal,
            ));

            $finalEvent = $finalEvents !== []
                ? $finalEvents[array_key_last($finalEvents)]
                : null;

            if ($finalEvent !== null) {
                $result['time_to_final_transcript_ms'] =
                    $finalEvent->receivedAtMs;
            }

            if (
                $audioCompletedAt !== null
                && $finalEvent !== null
            ) {
                $audioCompletedMs =
                    ($audioCompletedAt - $startedAt)
                    / 1_000_000;

                $result['finalization_latency_ms'] = round(
                    max(
                        0,
                        $finalEvent->receivedAtMs
                        - $audioCompletedMs,
                    ),
                    2,
                );
            }

            $result['total_duration_ms'] = round(
                ($completedAt - $startedAt) / 1_000_000,
                2,
            );
        } catch (Throwable $exception) {
            $result['error'] = $exception::class;

            $result['total_duration_ms'] = round(
                (hrtime(true) - $startedAt) / 1_000_000,
                2,
            );
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
        int $chunkIntervalMs,
        callable $onCompleted,
    ): iterable {
        if ($chunkSizeBytes <= 0) {
            throw new RuntimeException(
                'Streaming benchmark chunk size must be greater than zero.'
            );
        }

        if ($chunkIntervalMs < 0) {
            throw new RuntimeException(
                'Streaming benchmark chunk interval cannot be negative.'
            );
        }

        $length = strlen($audio);

        for ($offset = 0; $offset < $length; $offset += $chunkSizeBytes) {
            yield substr(
                $audio,
                $offset,
                $chunkSizeBytes,
            );

            if (
                $chunkIntervalMs > 0
                && $offset + $chunkSizeBytes < $length
            ) {
                usleep($chunkIntervalMs * 1000);
            }
        }

        $onCompleted();
    }
}
