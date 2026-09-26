<?php

namespace App\Services\Benchmark;

use RuntimeException;

final readonly class RealtimeTranslationBenchmarkEvaluator
{
    public function __construct(
        private CriticalElementEvaluator $criticalElementEvaluator,
        private BenchmarkTextNormalizer $normalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $benchmark
     * @param  array<string, mixed>  $dataset
     * @return array<string, mixed>
     */
    public function evaluate(
        array $benchmark,
        array $dataset,
    ): array {
        $results =
            $benchmark['results']
            ?? [];

        $phrases =
            $dataset['phrases']
            ?? [];

        if (! is_array($results)) {
            throw new RuntimeException(
                'Realtime translation benchmark results are invalid.',
            );
        }

        if (! is_array($phrases)) {
            throw new RuntimeException(
                'Translation benchmark dataset phrases are invalid.',
            );
        }

        $datasetByPhraseId =
            $this->indexDatasetByPhraseId(
                $phrases,
            );

        $evaluatedResults = [];
        $allRuns = [];

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $evaluatedResult =
                $this->evaluateFixture(
                    result: $result,
                    datasetByPhraseId: $datasetByPhraseId,
                );

            $evaluatedResults[] =
                $evaluatedResult;

            $runs =
                $evaluatedResult['runs']
                ?? [];

            if (! is_array($runs)) {
                continue;
            }

            foreach ($runs as $run) {
                if (is_array($run)) {
                    $allRuns[] = $run;
                }
            }
        }

        $benchmark['results'] =
            $evaluatedResults;

        $benchmark['evaluation'] =
            $this->buildSummary(
                $allRuns,
            );

        return $benchmark;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, array<string, mixed>>  $datasetByPhraseId
     * @return array<string, mixed>
     */
    private function evaluateFixture(
        array $result,
        array $datasetByPhraseId,
    ): array {
        $translationFixtureId =
            $result['translation_fixture_id']
            ?? null;

        if (
            ! is_string($translationFixtureId)
            || $translationFixtureId === ''
        ) {
            throw new RuntimeException(
                'Realtime translation benchmark result is missing translation_fixture_id.',
            );
        }

        $datasetPhrase =
            $datasetByPhraseId[
                $translationFixtureId
            ] ?? null;

        if (! is_array($datasetPhrase)) {
            throw new RuntimeException(
                "Translation benchmark dataset phrase not found: {$translationFixtureId}",
            );
        }

        $criticalElements =
            $datasetPhrase[
                'critical_elements'
            ] ?? [];

        if (! is_array($criticalElements)) {
            $criticalElements = [];
        }

        $reference =
            $datasetPhrase['reference']
            ?? null;

        if (! is_string($reference)) {
            $reference = null;
        }

        $runs =
            $result['runs']
            ?? [];

        if (! is_array($runs)) {
            throw new RuntimeException(
                "Realtime translation benchmark runs are invalid: {$translationFixtureId}",
            );
        }

        $evaluatedRuns = [];

        foreach ($runs as $run) {
            if (! is_array($run)) {
                continue;
            }

            $evaluatedRuns[] =
                $this->evaluateRun(
                    run: $run,
                    reference: $reference,
                    criticalElements: $criticalElements,
                );
        }

        $result['reference'] =
            $reference
            ?? ($result['reference'] ?? null);

        $result['critical_elements'] =
            $criticalElements;

        $result['runs'] =
            $evaluatedRuns;

        $result['evaluation'] =
            $this->buildSummary(
                $evaluatedRuns,
            );

        return $result;
    }

    /**
     * @param  array<string, mixed>  $run
     * @param  array<int, mixed>  $criticalElements
     * @return array<string, mixed>
     */
    private function evaluateRun(
        array $run,
        ?string $reference,
        array $criticalElements,
    ): array {
        $successful =
            ($run['success'] ?? false)
            === true;

        $translatedText =
            $run['translated_text']
            ?? null;

        $criticalEvaluation =
            $this
                ->criticalElementEvaluator
                ->evaluate(
                    actual: $translatedText,

                    criticalElements: $criticalElements,

                    successful: $successful,
                );

        $normalizedReference = null;
        $normalizedTranslatedText = null;
        $normalizedReferenceExact = null;

        if (
            $successful
            && is_string($reference)
            && is_string($translatedText)
        ) {
            $normalizedReference =
                $this->normalizer->normalize(
                    $reference,
                );

            $normalizedTranslatedText =
                $this->normalizer->normalize(
                    $translatedText,
                );

            $normalizedReferenceExact =
                $normalizedReference
                === $normalizedTranslatedText;
        }

        $audibleRelativeToInputEnd =
            $this->number(
                $run[
                    'first_audible_audio_available_relative_to_input_end_ms'
                ] ?? null,
            );

        $stopToAudioReadyMs =
            $audibleRelativeToInputEnd === null
                ? null
                : max(
                    0.0,
                    $audibleRelativeToInputEnd,
                );

        $sessionClosedMs =
            $this->number(
                $run['session_closed_ms']
                ?? null,
            );

        $inputFinishedMs =
            $this->number(
                $run['input_finished_ms']
                ?? null,
            );

        $sessionCloseAfterInputEndMs =
            $sessionClosedMs !== null
            && $inputFinishedMs !== null
                ? $sessionClosedMs
                    - $inputFinishedMs
                : null;

        $manualReviewReasons = [];

        if ($successful) {
            if (
                $criticalEvaluation['ok']
                === false
            ) {
                $manualReviewReasons[] =
                    'missing_critical_elements';
            }

            if (
                $audibleRelativeToInputEnd
                === null
            ) {
                $manualReviewReasons[] =
                    'audible_audio_not_detected';
            }
        }

        $run['evaluation'] = [
            'normalized_reference' => $normalizedReference,

            'normalized_translated_text' => $normalizedTranslatedText,

            /*
             * Diagnostic only.
             *
             * A valid translation does not have to match
             * the reference wording exactly.
             */
            'normalized_reference_exact' => $normalizedReferenceExact,

            'critical_elements_ok' => $criticalEvaluation['ok'],

            'critical_elements_passed' => $criticalEvaluation['passed'],

            'critical_elements_total' => $criticalEvaluation['total'],

            'missing_critical_elements' => $criticalEvaluation[
                    'missing_critical_elements'
                ],

            'manual_review_required' => $manualReviewReasons !== [],

            'manual_review_reasons' => $manualReviewReasons,

            'latency' => [
                'stop_to_audio_ready_ms' => $stopToAudioReadyMs,

                'session_close_after_input_end_ms' => $sessionCloseAfterInputEndMs,
            ],
        ];

        return $run;
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return array<string, mixed>
     */
    private function buildSummary(
        array $runs,
    ): array {
        $successful = 0;
        $failed = 0;

        $criticalElementsPassed = 0;
        $criticalElementsTotal = 0;
        $runsWithMissingCriticalElements = 0;
        $manualReviewRequiredRuns = 0;

        $audibleDetectedRuns = 0;
        $audibleMissingRuns = 0;

        $setupBeforeFirstInput = [];
        $firstTranslationText = [];
        $firstAudioPacketRelativeToInputEnd = [];
        $rawPlaybackRelativeToInputEnd = [];
        $audibleAvailableFromFirstInput = [];
        $audibleAvailableRelativeToInputEnd = [];
        $stopToAudioReady = [];
        $sessionCloseAfterInputEnd = [];

        foreach ($runs as $run) {
            $isSuccessful =
                ($run['success'] ?? false)
                === true;

            if ($isSuccessful) {
                $successful++;
            } else {
                $failed++;
            }

            $evaluation =
                $run['evaluation']
                ?? [];

            if (is_array($evaluation)) {
                $criticalElementsPassed +=
                    (int) (
                        $evaluation[
                            'critical_elements_passed'
                        ] ?? 0
                    );

                $criticalElementsTotal +=
                    (int) (
                        $evaluation[
                            'critical_elements_total'
                        ] ?? 0
                    );

                $missing =
                    $evaluation[
                        'missing_critical_elements'
                    ] ?? [];

                if (
                    is_array($missing)
                    && $missing !== []
                ) {
                    $runsWithMissingCriticalElements++;
                }

                if (
                    ($evaluation[
                        'manual_review_required'
                    ] ?? false)
                    === true
                ) {
                    $manualReviewRequiredRuns++;
                }

                $latency =
                    $evaluation['latency']
                    ?? [];

                if (is_array($latency)) {
                    $this->appendNumber(
                        $stopToAudioReady,
                        $latency[
                            'stop_to_audio_ready_ms'
                        ] ?? null,
                    );

                    $this->appendNumber(
                        $sessionCloseAfterInputEnd,
                        $latency[
                            'session_close_after_input_end_ms'
                        ] ?? null,
                    );
                }
            }

            if (! $isSuccessful) {
                continue;
            }

            $audibleRelativeToInputEnd =
                $this->number(
                    $run[
                        'first_audible_audio_available_relative_to_input_end_ms'
                    ] ?? null,
                );

            if ($audibleRelativeToInputEnd !== null) {
                $audibleDetectedRuns++;
            } else {
                $audibleMissingRuns++;
            }

            $this->appendNumber(
                $setupBeforeFirstInput,
                $run[
                    'first_input_audio_sent_ms'
                ] ?? null,
            );

            $this->appendNumber(
                $firstTranslationText,
                $run[
                    'time_to_first_translation_text_ms'
                ] ?? null,
            );

            $this->appendNumber(
                $firstAudioPacketRelativeToInputEnd,
                $run[
                    'first_audio_packet_relative_to_input_end_ms'
                ] ?? null,
            );

            $this->appendNumber(
                $rawPlaybackRelativeToInputEnd,
                $run[
                    'raw_playback_first_audible_audio_relative_to_input_end_ms'
                ] ?? null,
            );

            $this->appendNumber(
                $audibleAvailableFromFirstInput,
                $run[
                    'first_audible_audio_available_relative_to_first_input_ms'
                ] ?? null,
            );

            $this->appendNumber(
                $audibleAvailableRelativeToInputEnd,
                $run[
                    'first_audible_audio_available_relative_to_input_end_ms'
                ] ?? null,
            );
        }

        $audibleEligibleRuns =
            $audibleDetectedRuns
            + $audibleMissingRuns;

        return [
            'successful_runs' => $successful,

            'failed_runs' => $failed,

            'manual_review_required_runs' => $manualReviewRequiredRuns,

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

                'runs_with_missing_critical_elements' => $runsWithMissingCriticalElements,
            ],

            'audio_output' => [
                'audible_detected_runs' => $audibleDetectedRuns,

                'audible_missing_runs' => $audibleMissingRuns,

                'audible_detection_rate_percent' => $audibleEligibleRuns > 0
                        ? round(
                            (
                                $audibleDetectedRuns
                                / $audibleEligibleRuns
                            ) * 100,
                            2,
                        )
                        : null,
            ],

            'latency' => [
                'setup_before_first_input_ms' => $this->summarize(
                    $setupBeforeFirstInput,
                ),

                'time_to_first_translation_text_ms' => $this->summarize(
                    $firstTranslationText,
                ),

                'first_audio_packet_relative_to_input_end_ms' => $this->summarize(
                    $firstAudioPacketRelativeToInputEnd,
                ),

                'raw_playback_first_audible_audio_relative_to_input_end_ms' => $this->summarize(
                    $rawPlaybackRelativeToInputEnd,
                ),

                'first_audible_audio_available_relative_to_first_input_ms' => $this->summarize(
                    $audibleAvailableFromFirstInput,
                ),

                'first_audible_audio_available_relative_to_input_end_ms' => $this->summarize(
                    $audibleAvailableRelativeToInputEnd,
                ),

                'stop_to_audio_ready_ms' => $this->summarize(
                    $stopToAudioReady,
                ),

                'session_close_after_input_end_ms' => $this->summarize(
                    $sessionCloseAfterInputEnd,
                ),
            ],
        ];
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
                $phrase['id']
                ?? null;

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

    /**
     * @param  list<float>  $values
     */
    private function appendNumber(
        array &$values,
        mixed $value,
    ): void {
        $number =
            $this->number(
                $value,
            );

        if ($number !== null) {
            $values[] = $number;
        }
    }

    private function number(
        mixed $value,
    ): ?float {
        if (
            ! is_int($value)
            && ! is_float($value)
        ) {
            return null;
        }

        return (float) $value;
    }
}
