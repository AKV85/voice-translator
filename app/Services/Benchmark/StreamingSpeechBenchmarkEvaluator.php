<?php

namespace App\Services\Benchmark;

final readonly class StreamingSpeechBenchmarkEvaluator
{
    public function __construct(
        private SpeechBenchmarkEvaluator $speechBenchmarkEvaluator,
    ) {}

    /**
     * @param  array<string, mixed>  $benchmark
     * @return array<string, mixed>
     */
    public function evaluate(array $benchmark): array
    {
        $benchmark =
            $this->speechBenchmarkEvaluator->evaluate(
                $benchmark,
            );

        $results = $this->results(
            $benchmark,
        );

        $evaluation =
            $benchmark['evaluation'] ?? [];

        if (! is_array($evaluation)) {
            $evaluation = [];
        }

        $evaluation['streaming_latency'] = [
            'time_to_first_transcript_ms' => $this->metricSummary(
                $results,
                'time_to_first_transcript_ms',
            ),

            'time_to_final_transcript_ms' => $this->metricSummary(
                $results,
                'time_to_final_transcript_ms',
            ),

            'finalization_latency_ms' => $this->metricSummary(
                $results,
                'finalization_latency_ms',
            ),

            'total_duration_ms' => $this->metricSummary(
                $results,
                'total_duration_ms',
            ),

            'preparation_duration_ms' => $this->metricSummary(
                $results,
                'preparation_duration_ms',
            ),

            'estimated_provider_setup_ms' => $this->estimatedProviderSetupSummary(
                $results,
            ),
        ];

        $benchmark['evaluation'] =
            $evaluation;

        return $benchmark;
    }

    /**
     * @param  array<string, mixed>  $benchmark
     * @return array<int, array<string, mixed>>
     */
    private function results(
        array $benchmark,
    ): array {
        $rawResults =
            $benchmark['results'] ?? [];

        if (! is_array($rawResults)) {
            return [];
        }

        $results = [];

        foreach ($rawResults as $result) {
            if (is_array($result)) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, int|float|null>
     */
    private function metricSummary(
        array $results,
        string $metric,
    ): array {
        $values = [];

        foreach ($results as $result) {
            if (
                ($result['success'] ?? false)
                !== true
            ) {
                continue;
            }

            $value =
                $result[$metric] ?? null;

            if (! is_numeric($value)) {
                continue;
            }

            $values[] = (float) $value;
        }

        return $this->summary(
            $values,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, int|float|null>
     */
    private function estimatedProviderSetupSummary(
        array $results,
    ): array {
        $values = [];

        foreach ($results as $result) {
            if (
                ($result['success'] ?? false)
                !== true
            ) {
                continue;
            }

            $timeToFinal =
                $result['time_to_final_transcript_ms']
                ?? null;

            $audioDuration =
                $result['audio_duration_ms']
                ?? null;

            $finalizationLatency =
                $result['finalization_latency_ms']
                ?? null;

            if (
                ! is_numeric($timeToFinal)
                || ! is_numeric($audioDuration)
                || ! is_numeric($finalizationLatency)
            ) {
                continue;
            }

            $values[] = max(
                0.0,
                (float) $timeToFinal
                - (float) $audioDuration
                - (float) $finalizationLatency,
            );
        }

        return $this->summary(
            $values,
        );
    }

    /**
     * @param  array<int, float>  $values
     * @return array<string, int|float|null>
     */
    private function summary(
        array $values,
    ): array {
        return [
            'count' => count($values),

            'average' => $this->average(
                $values,
            ),

            'median' => $this->percentile(
                $values,
                0.50,
            ),

            'min' => $this->minimum(
                $values,
            ),

            'max' => $this->maximum(
                $values,
            ),

            'p95' => $this->percentile(
                $values,
                0.95,
            ),
        ];
    }

    /**
     * @param  array<int, float>  $values
     */
    private function average(
        array $values,
    ): ?float {
        if ($values === []) {
            return null;
        }

        return round(
            array_sum($values)
            / count($values),
            2,
        );
    }

    /**
     * @param  array<int, float>  $values
     */
    private function minimum(
        array $values,
    ): ?float {
        if ($values === []) {
            return null;
        }

        return round(
            min($values),
            2,
        );
    }

    /**
     * @param  array<int, float>  $values
     */
    private function maximum(
        array $values,
    ): ?float {
        if ($values === []) {
            return null;
        }

        return round(
            max($values),
            2,
        );
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

        sort(
            $values,
            SORT_NUMERIC,
        );

        if (count($values) === 1) {
            return round(
                $values[0],
                2,
            );
        }

        $position =
            (count($values) - 1)
            * $percentile;

        $lower =
            (int) floor($position);

        $upper =
            (int) ceil($position);

        if ($lower === $upper) {
            return round(
                $values[$lower],
                2,
            );
        }

        $weight =
            $position - $lower;

        $value =
            $values[$lower]
            + (
                (
                    $values[$upper]
                    - $values[$lower]
                )
                * $weight
            );

        return round(
            $value,
            2,
        );
    }
}
