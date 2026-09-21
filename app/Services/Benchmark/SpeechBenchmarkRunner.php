<?php

namespace App\Services\Benchmark;

use App\Contracts\SpeechToTextProvider;
use App\DTO\AudioInput;
use App\Enums\Language;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;
use Throwable;

class SpeechBenchmarkRunner
{
    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public function run(
        SpeechToTextProvider $provider,
        string $profileName,
        string $providerName,
        string $modelName,
        string $datasetPath,
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
            );
        }

        return [
            'benchmark_version' => 1,
            'dataset_version' => $dataset['version'] ?? null,
            'profile' => $profileName,
            'provider' => $providerName,
            'model' => $modelName,
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
        SpeechToTextProvider $provider,
        string $providerName,
        string $modelName,
        array $phrase,
        string $datasetDirectory,
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
            'latency_ms' => null,
            'error' => null,
            'provider' => $providerName,
            'model' => $modelName,
            'recorded_at' => now()->toIso8601String(),
        ];

        if (! File::exists($audioPath)) {
            $result['error'] = 'Audio fixture not found.';

            return $result;
        }

        $startedAt = hrtime(true);

        try {
            $audio = new AudioInput(
                content: File::get($audioPath),
                mimeType: File::mimeType($audioPath) ?: 'audio/webm',
            );

            $transcription = $provider->transcribe(
                audio: $audio,
                sourceLanguage: Language::from($phrase['language']),
            );

            $result['actual'] = $transcription->text;
            $result['success'] = true;
        } catch (Throwable $exception) {
            $result['error'] = $exception::class;
        } finally {
            $result['latency_ms'] = round(
                (hrtime(true) - $startedAt) / 1_000_000,
                2,
            );
        }

        return $result;
    }
}
