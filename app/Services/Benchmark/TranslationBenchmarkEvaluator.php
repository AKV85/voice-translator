<?php

namespace App\Services\Benchmark;

use RuntimeException;

final class TranslationBenchmarkEvaluator
{
    /**
     * @param  array<string, mixed>  $benchmark
     * @param  array<string, mixed>  $dataset
     * @return array<string, mixed>
     */
    public function evaluate(
        array $benchmark,
        array $dataset,
    ): array {
        $results = $benchmark['results'] ?? [];
        $phrases = $dataset['phrases'] ?? [];

        if (! is_array($results)) {
            throw new RuntimeException(
                'Translation benchmark results are invalid.',
            );
        }

        if (! is_array($phrases)) {
            throw new RuntimeException(
                'Translation benchmark dataset phrases are invalid.',
            );
        }

        $datasetByPhraseId =
            $this->indexDatasetByPhraseId($phrases);

        $evaluatedResults = [];

        $successful = 0;
        $failed = 0;

        $criticalElementsPassed = 0;
        $criticalElementsTotal = 0;
        $fixturesWithMissingCriticalElements = 0;

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $phraseId = $result['phrase_id'] ?? null;

            if (
                ! is_string($phraseId)
                || $phraseId === ''
            ) {
                throw new RuntimeException(
                    'Translation benchmark result is missing phrase_id.',
                );
            }

            $datasetPhrase =
                $datasetByPhraseId[$phraseId]
                ?? null;

            if (! is_array($datasetPhrase)) {
                throw new RuntimeException(
                    "Translation benchmark dataset phrase not found: {$phraseId}"
                );
            }

            $isSuccessful =
                ($result['success'] ?? false) === true;

            if ($isSuccessful) {
                $successful++;
            } else {
                $failed++;
            }

            $criticalElements =
                $datasetPhrase['critical_elements']
                ?? [];

            if (! is_array($criticalElements)) {
                $criticalElements = [];
            }

            $criticalEvaluation =
                $this->evaluateCriticalElements(
                    actual: $result['actual'] ?? null,
                    criticalElements: $criticalElements,
                    successful: $isSuccessful,
                );

            $criticalElementsPassed +=
                $criticalEvaluation['passed'];

            $criticalElementsTotal +=
                $criticalEvaluation['total'];

            if (
                $criticalEvaluation[
                    'missing_critical_elements'
                ] !== []
            ) {
                $fixturesWithMissingCriticalElements++;
            }

            $result['evaluation'] = [
                'critical_elements_ok' => $criticalEvaluation['ok'],

                'critical_elements_passed' => $criticalEvaluation['passed'],

                'critical_elements_total' => $criticalEvaluation['total'],

                'missing_critical_elements' => $criticalEvaluation[
                        'missing_critical_elements'
                    ],
            ];

            $evaluatedResults[] = $result;
        }

        $benchmark['results'] =
            $evaluatedResults;

        $benchmark['evaluation'] = [
            'successful' => $successful,
            'failed' => $failed,

            'critical_elements' => [
                'passed' => $criticalElementsPassed,

                'total' => $criticalElementsTotal,

                'failed' => max(
                    0,
                    $criticalElementsTotal
                    - $criticalElementsPassed,
                ),

                'pass_rate_percent' => $criticalElementsTotal > 0
                        ? round(
                            (
                                $criticalElementsPassed
                                / $criticalElementsTotal
                            ) * 100,
                            2,
                        )
                        : null,

                'fixtures_with_missing_critical_elements' => $fixturesWithMissingCriticalElements,
            ],

            'latency' => $this->evaluateLatency(
                $evaluatedResults,
            ),
        ];

        return $benchmark;
    }

    /**
     * @param  array<int, mixed>  $phrases
     * @return array<string, array<string, mixed>>
     */
    private function indexDatasetByPhraseId(
        array $phrases,
    ): array {
        $indexed = [];

        foreach ($phrases as $phrase) {
            if (! is_array($phrase)) {
                continue;
            }

            $phraseId =
                $phrase['id'] ?? null;

            if (
                ! is_string($phraseId)
                || $phraseId === ''
            ) {
                continue;
            }

            $indexed[$phraseId] =
                $phrase;
        }

        return $indexed;
    }

    /**
     * @param  array<int, mixed>  $criticalElements
     * @return array{
     *     ok: bool|null,
     *     passed: int,
     *     total: int,
     *     missing_critical_elements: list<string>
     * }
     */
    private function evaluateCriticalElements(
        mixed $actual,
        array $criticalElements,
        bool $successful,
    ): array {
        if (
            ! $successful
            || ! is_string($actual)
        ) {
            return [
                'ok' => null,
                'passed' => 0,
                'total' => 0,
                'missing_critical_elements' => [],
            ];
        }

        $normalizedActual =
            $this->normalize($actual);

        $passed = 0;
        $total = 0;
        $missing = [];

        foreach ($criticalElements as $element) {
            if (! is_array($element)) {
                continue;
            }

            $name =
                $element['name'] ?? null;

            $accepted =
                $element['accepted'] ?? null;

            if (
                ! is_string($name)
                || ! is_array($accepted)
            ) {
                continue;
            }

            $acceptedValues = array_values(
                array_filter(
                    $accepted,
                    static fn (mixed $value): bool => is_string($value)
                        && $value !== '',
                ),
            );

            if ($acceptedValues === []) {
                continue;
            }

            $total++;

            $matched = false;

            foreach ($acceptedValues as $acceptedValue) {
                if (
                    $this->containsAcceptedValue(
                        normalizedActual: $normalizedActual,
                        accepted: $acceptedValue,
                    )
                ) {
                    $matched = true;

                    break;
                }
            }

            if ($matched) {
                $passed++;

                continue;
            }

            $missing[] = $name;
        }

        return [
            'ok' => $total > 0
                ? $passed === $total
                : null,

            'passed' => $passed,
            'total' => $total,

            'missing_critical_elements' => $missing,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function evaluateLatency(
        array $results,
    ): array {
        $coldStart = null;

        if ($results !== []) {
            $first = $results[0];

            $latency =
                $first['latency_ms']
                ?? null;

            $coldStart = [
                'phrase_id' => $first['phrase_id']
                    ?? null,

                'success' => ($first['success'] ?? false)
                    === true,

                'latency_ms' => is_int($latency)
                    || is_float($latency)
                        ? (float) $latency
                        : null,
            ];
        }

        $warmLatencies = [];
        $warmFailedCount = 0;

        foreach (
            array_slice($results, 1) as $result
        ) {
            $successful =
                ($result['success'] ?? false)
                === true;

            if (! $successful) {
                $warmFailedCount++;

                continue;
            }

            $latency =
                $result['latency_ms']
                ?? null;

            if (
                is_int($latency)
                || is_float($latency)
            ) {
                $warmLatencies[] =
                    (float) $latency;
            }
        }

        return [
            'cold_start' => $coldStart,

            'warm' => $this->summarize(
                $warmLatencies,
            ),

            'warm_failed_count' => $warmFailedCount,
        ];
    }

    /**
     * @param  list<float>  $values
     * @return array{
     *     count: int,
     *     avg_ms: float|null,
     *     median_ms: float|null,
     *     min_ms: float|null,
     *     max_ms: float|null,
     *     p95_ms: float|null
     * }
     */
    private function summarize(
        array $values,
    ): array {
        if ($values === []) {
            return [
                'count' => 0,
                'avg_ms' => null,
                'median_ms' => null,
                'min_ms' => null,
                'max_ms' => null,
                'p95_ms' => null,
            ];
        }

        sort(
            $values,
            SORT_NUMERIC,
        );

        return [
            'count' => count($values),

            'avg_ms' => round(
                array_sum($values)
                / count($values),
                2,
            ),

            'median_ms' => round(
                $this->percentile(
                    $values,
                    50,
                ),
                2,
            ),

            'min_ms' => round(
                min($values),
                2,
            ),

            'max_ms' => round(
                max($values),
                2,
            ),

            'p95_ms' => round(
                $this->percentile(
                    $values,
                    95,
                ),
                2,
            ),
        ];
    }

    /**
     * @param  list<float>  $sortedValues
     */
    private function percentile(
        array $sortedValues,
        float $percentile,
    ): float {
        $count =
            count($sortedValues);

        if ($count === 1) {
            return $sortedValues[0];
        }

        $position =
            ($percentile / 100)
            * ($count - 1);

        $lowerIndex =
            (int) floor($position);

        $upperIndex =
            (int) ceil($position);

        if ($lowerIndex === $upperIndex) {
            return $sortedValues[
                $lowerIndex
            ];
        }

        $fraction =
            $position
            - $lowerIndex;

        return $sortedValues[$lowerIndex]
            + (
                $sortedValues[$upperIndex]
                - $sortedValues[$lowerIndex]
            ) * $fraction;
    }

    private function containsAcceptedValue(
        string $normalizedActual,
        string $accepted,
    ): bool {
        $normalizedAccepted =
            $this->normalize($accepted);

        if ($normalizedAccepted === '') {
            return false;
        }

        $pattern = sprintf(
            '/(?<![\p{L}\p{N}])%s(?![\p{L}\p{N}])/u',
            preg_quote(
                $normalizedAccepted,
                '/',
            ),
        );

        return preg_match(
            $pattern,
            $normalizedActual,
        ) === 1;
    }

    private function normalize(
        string $text,
    ): string {
        $text = mb_strtolower(
            trim($text),
        );

        $text = str_replace(
            'ё',
            'е',
            $text,
        );

        $text = preg_replace(
            '/[^\p{L}\p{N}:]+/u',
            ' ',
            $text,
        ) ?? '';

        return preg_replace(
            '/\s+/u',
            ' ',
            trim($text),
        ) ?? '';
    }
}
