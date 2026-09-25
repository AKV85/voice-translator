<?php

namespace App\Services\Benchmark;

use App\Contracts\TranslationProvider;
use App\Enums\Language;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;
use Throwable;

final class TranslationBenchmarkRunner
{
    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public function run(
        TranslationProvider $provider,
        string $profileName,
        string $providerName,
        string $modelName,
        string $datasetPath,
        ?string $languagePair = null,
    ): array {
        if (! File::exists($datasetPath)) {
            throw new RuntimeException(
                "Translation benchmark dataset not found: {$datasetPath}"
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
                'Translation benchmark dataset phrases are invalid.'
            );
        }

        if ($languagePair !== null) {
            $phrases = array_values(
                array_filter(
                    $phrases,
                    static function (mixed $phrase) use (
                        $languagePair
                    ): bool {
                        if (! is_array($phrase)) {
                            return false;
                        }

                        $sourceLanguage =
                            $phrase['source_language']
                            ?? null;

                        $targetLanguage =
                            $phrase['target_language']
                            ?? null;

                        if (
                            ! is_string($sourceLanguage)
                            || ! is_string($targetLanguage)
                        ) {
                            return false;
                        }

                        return "{$sourceLanguage}-{$targetLanguage}"
                            === $languagePair;
                    },
                ),
            );

            if ($phrases === []) {
                throw new RuntimeException(
                    "Translation benchmark language pair not found: {$languagePair}"
                );
            }
        }

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
            );
        }

        return [
            'benchmark_version' => 1,
            'benchmark_type' => 'translation',
            'dataset_version' => $dataset['version'] ?? null,
            'profile' => $profileName,
            'provider' => $providerName,
            'model' => $modelName,
            'language_pair' => $languagePair,
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
        TranslationProvider $provider,
        string $providerName,
        string $modelName,
        array $phrase,
    ): array {
        $source = $phrase['source'] ?? null;
        $reference = $phrase['reference'] ?? null;
        $sourceLanguage = $phrase['source_language'] ?? null;
        $targetLanguage = $phrase['target_language'] ?? null;

        if (
            ! is_string($source)
            || ! is_string($reference)
            || ! is_string($sourceLanguage)
            || ! is_string($targetLanguage)
        ) {
            throw new RuntimeException(
                'Invalid translation benchmark phrase.'
            );
        }

        $result = [
            'phrase_id' => $phrase['id'] ?? null,
            'category' => $phrase['category'] ?? null,
            'source_language' => $sourceLanguage,
            'target_language' => $targetLanguage,
            'source' => $source,
            'reference' => $reference,
            'critical_elements' => $phrase['critical_elements'] ?? [],
            'actual' => null,
            'success' => false,
            'latency_ms' => null,
            'error' => null,
            'provider' => $providerName,
            'model' => $modelName,
            'recorded_at' => now()->toIso8601String(),
        ];

        $startedAt = hrtime(true);

        try {
            $translation = $provider->translate(
                text: $source,
                sourceLanguage: Language::from(
                    $sourceLanguage,
                ),
                targetLanguage: Language::from(
                    $targetLanguage,
                ),
            );

            $result['latency_ms'] = round(
                (
                    hrtime(true)
                    - $startedAt
                ) / 1_000_000,
                2,
            );

            $result['actual'] =
                $translation->text;

            $result['success'] = true;
        } catch (Throwable $exception) {
            $result['latency_ms'] = round(
                (
                    hrtime(true)
                    - $startedAt
                ) / 1_000_000,
                2,
            );

            $result['error'] =
                $exception::class;
        }

        return $result;
    }
}
