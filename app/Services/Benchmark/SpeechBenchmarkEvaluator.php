<?php

namespace App\Services\Benchmark;

class SpeechBenchmarkEvaluator
{
    public function __construct(
        private readonly SpeechTranscriptNormalizer $normalizer,
        private readonly WordErrorRateCalculator $werCalculator,
    ) {}

    /**
     * @param  array<string, mixed>  $benchmark
     * @return array<string, mixed>
     */
    public function evaluate(array $benchmark): array
    {
        $results = [];

        foreach ($benchmark['results'] ?? [] as $result) {
            $results[] = $this->evaluateResult($result);
        }

        $benchmark['results'] = $results;
        $benchmark['evaluation'] = [
            'version' => 1,
            'evaluated_at' => now()->toIso8601String(),
            'summary' => $this->summary($results),
        ];

        return $benchmark;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function evaluateResult(array $result): array
    {
        $expected = $result['expected'] ?? null;
        $actual = $result['actual'] ?? null;
        $success = $result['success'] ?? false;

        if (
            $success !== true
            || ! is_string($expected)
            || ! is_string($actual)
        ) {
            $result['evaluation'] = [
                'raw_wer' => null,
                'normalized_wer' => null,
                'normalized_expected' => null,
                'normalized_actual' => null,
                'classification' => 'provider_failure',
            ];

            return $result;
        }

        $normalizedExpected = $this->normalizer->normalize(
            $expected,
        );

        $normalizedActual = $this->normalizer->normalize(
            $actual,
        );

        $rawWer = $this->werCalculator->calculate(
            $expected,
            $actual,
        );

        $normalizedWer = $this->werCalculator->calculate(
            $normalizedExpected,
            $normalizedActual,
        );

        $rawExact = $expected === $actual;
        $normalizedExact =
            $normalizedExpected === $normalizedActual;

        $classification = match (true) {
            $rawExact => 'exact_match',
            $normalizedExact => 'normalization_only',
            default => 'content_mismatch',
        };

        $result['evaluation'] = [
            'raw_wer' => round($rawWer, 4),
            'normalized_wer' => round($normalizedWer, 4),
            'normalized_expected' => $normalizedExpected,
            'normalized_actual' => $normalizedActual,
            'classification' => $classification,
        ];

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function summary(array $results): array
    {
        $successful = array_values(array_filter(
            $results,
            fn (array $result): bool => ($result['success'] ?? false) === true,
        ));

        $failedCount = count($results) - count($successful);

        $rawWerValues = [];
        $normalizedWerValues = [];
        $latencies = [];

        $classifications = [
            'exact_match' => 0,
            'normalization_only' => 0,
            'content_mismatch' => 0,
            'provider_failure' => 0,
        ];

        foreach ($results as $result) {
            $classification =
                $result['evaluation']['classification']
                ?? 'provider_failure';

            if (isset($classifications[$classification])) {
                $classifications[$classification]++;
            }

            $rawWer = $result['evaluation']['raw_wer'] ?? null;
            $normalizedWer =
                $result['evaluation']['normalized_wer']
                ?? null;

            if (is_numeric($rawWer)) {
                $rawWerValues[] = (float) $rawWer;
            }

            if (is_numeric($normalizedWer)) {
                $normalizedWerValues[] =
                    (float) $normalizedWer;
            }

            if (
                ($result['success'] ?? false) === true
                && is_numeric($result['latency_ms'] ?? null)
            ) {
                $latencies[] =
                    (float) $result['latency_ms'];
            }
        }

        return [
            'fixture_count' => count($results),
            'successful_count' => count($successful),
            'failed_count' => $failedCount,

            'exact_match_count' => $classifications['exact_match'],

            'normalization_only_count' => $classifications['normalization_only'],

            'content_mismatch_count' => $classifications['content_mismatch'],

            'provider_failure_count' => $classifications['provider_failure'],

            'average_raw_wer' => $this->average($rawWerValues),

            'average_normalized_wer' => $this->average($normalizedWerValues),

            'latency_ms' => [
                'average' => $this->average($latencies),
                'median' => $this->percentile(
                    $latencies,
                    0.50,
                ),
                'min' => $this->minimum($latencies),
                'max' => $this->maximum($latencies),
                'p95' => $this->percentile(
                    $latencies,
                    0.95,
                ),
            ],
        ];
    }

    /**
     * @param  array<int, float>  $values
     */
    private function average(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return round(
            array_sum($values) / count($values),
            4,
        );
    }

    /**
     * @param  array<int, float>  $values
     */
    private function minimum(array $values): ?float
    {
        return $values === []
            ? null
            : round(min($values), 2);
    }

    /**
     * @param  array<int, float>  $values
     */
    private function maximum(array $values): ?float
    {
        return $values === []
            ? null
            : round(max($values), 2);
    }

    /**
     * @param  array<int, float>  $values
     */
    private function percentile(
        array $values,
        float $percentile,
    ): ?float {
        if ($values === []) {
            return null;
        }

        sort($values, SORT_NUMERIC);

        if (count($values) === 1) {
            return round($values[0], 2);
        }

        $position =
            (count($values) - 1) * $percentile;

        $lower = (int) floor($position);
        $upper = (int) ceil($position);

        if ($lower === $upper) {
            return round($values[$lower], 2);
        }

        $weight = $position - $lower;

        $value =
            $values[$lower]
            + (($values[$upper] - $values[$lower]) * $weight);

        return round($value, 2);
    }
}
