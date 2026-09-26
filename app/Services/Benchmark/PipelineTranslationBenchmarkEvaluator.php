<?php

namespace App\Services\Benchmark;

use RuntimeException;

final readonly class PipelineTranslationBenchmarkEvaluator
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
                'Pipeline translation benchmark results are invalid.',
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
                'Pipeline translation benchmark result is missing translation_fixture_id.',
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
                "Pipeline translation benchmark runs are invalid: {$translationFixtureId}",
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
        $translationSuccessful =
            ($run['translation_success'] ?? false)
            === true;

        $ttsSuccessful =
            ($run['tts_success'] ?? false)
            === true;

        $audioSuccessful =
            ($run['audio_success'] ?? false)
            === true;

        $translatedText =
            $run['translated_text']
            ?? null;

        /*
         * Critical quality belongs to the translation stage.
         *
         * A later TTS failure must not erase a valid translated
         * text that has already been produced.
         */
        $criticalEvaluation =
            $this
                ->criticalElementEvaluator
                ->evaluate(
                    actual: $translatedText,
                    criticalElements: $criticalElements,
                    successful: $translationSuccessful,
                );

        $normalizedReference = null;
        $normalizedTranslatedText = null;
        $normalizedReferenceExact = null;

        if (
            $translationSuccessful
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

        $audibleDetected =
            $ttsSuccessful
            && $audioSuccessful
            && $audibleRelativeToInputEnd !== null;

        $manualReviewReasons = [];

        if (
            $translationSuccessful
            && $criticalEvaluation['ok']
            === false
        ) {
            $manualReviewReasons[] =
                'missing_critical_elements';
        }

        if (
            $ttsSuccessful
            && ! $audibleDetected
        ) {
            $manualReviewReasons[] =
                'audible_audio_not_detected';
        }

        $run['evaluation'] = [
            'normalized_reference' => $normalizedReference,

            'normalized_translated_text' => $normalizedTranslatedText,

            /*
             * Diagnostic only.
             *
             * A semantically valid translation does not have
             * to reproduce the reference wording exactly.
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
        $successfulRuns = 0;
        $failedRuns = 0;

        $sttAttempted = 0;
        $sttSuccessful = 0;

        $translationAttempted = 0;
        $translationSuccessful = 0;

        $ttsAttempted = 0;
        $ttsSuccessful = 0;

        $criticalElementsPassed = 0;
        $criticalElementsTotal = 0;
        $runsWithMissingCriticalElements = 0;
        $manualReviewRequiredRuns = 0;

        $audibleEligibleRuns = 0;
        $audibleDetectedRuns = 0;
        $audibleMissingRuns = 0;

        $setupBeforeFirstInput = [];
        $sttAfterInputEnd = [];
        $translationLatency = [];
        $translationCompletedAfterInputEnd = [];
        $ttsFirstAudioChunkLatency = [];
        $ttsFirstAudibleLatency = [];
        $firstAudioChunkAfterInputEnd = [];
        $rawPlaybackAfterInputEnd = [];
        $audibleAvailableAfterInputEnd = [];
        $stopToAudioReady = [];
        $ttsDuration = [];
        $ttsCompletedAfterInputEnd = [];
        $totalDuration = [];

        foreach ($runs as $run) {
            $isSuccessful =
                ($run['success'] ?? false)
                === true;

            if ($isSuccessful) {
                $successfulRuns++;
            } else {
                $failedRuns++;
            }

            $failedStage =
                $run['failed_stage']
                ?? null;

            $sttIsSuccessful =
                ($run['stt_success'] ?? false)
                === true;

            $translationIsSuccessful =
                ($run['translation_success'] ?? false)
                === true;

            $ttsIsSuccessful =
                ($run['tts_success'] ?? false)
                === true;

            /*
             * STT is attempted for every run that reaches the
             * provider pipeline. A preparation failure is the
             * only case where STT was never attempted.
             */
            $sttWasAttempted =
                $isSuccessful
                || $failedStage !== 'preparation';

            if ($sttWasAttempted) {
                $sttAttempted++;

                if ($sttIsSuccessful) {
                    $sttSuccessful++;
                }
            }

            /*
             * Translation can only be attempted after STT
             * successfully returned a transcript.
             */
            if ($sttIsSuccessful) {
                $translationAttempted++;

                if ($translationIsSuccessful) {
                    $translationSuccessful++;
                }
            }

            /*
             * TTS can only be attempted after translation
             * successfully returned translated text.
             */
            if ($translationIsSuccessful) {
                $ttsAttempted++;

                if ($ttsIsSuccessful) {
                    $ttsSuccessful++;
                }
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
                }
            }

            if ($ttsIsSuccessful) {
                $audibleEligibleRuns++;

                $audioSuccessful =
                    ($run['audio_success'] ?? false)
                    === true;

                $audibleRelative =
                    $this->number(
                        $run[
                            'first_audible_audio_available_relative_to_input_end_ms'
                        ] ?? null,
                    );

                if (
                    $audioSuccessful
                    && $audibleRelative !== null
                ) {
                    $audibleDetectedRuns++;
                } else {
                    $audibleMissingRuns++;
                }
            }

            $this->appendNumber(
                $setupBeforeFirstInput,
                $run[
                    'first_input_audio_provided_ms'
                ] ?? null,
            );

            $this->appendNumber(
                $sttAfterInputEnd,
                $run[
                    'stt_completed_relative_to_input_end_ms'
                ] ?? null,
            );

            $this->appendNumber(
                $translationLatency,
                $run[
                    'translation_latency_ms'
                ] ?? null,
            );

            $this->appendNumber(
                $translationCompletedAfterInputEnd,
                $run[
                    'translation_completed_relative_to_input_end_ms'
                ] ?? null,
            );

            $this->appendNumber(
                $ttsFirstAudioChunkLatency,
                $run[
                    'tts_first_audio_chunk_latency_ms'
                ] ?? null,
            );

            $this->appendNumber(
                $ttsFirstAudibleLatency,
                $run[
                    'tts_first_audible_available_latency_ms'
                ] ?? null,
            );

            $this->appendNumber(
                $firstAudioChunkAfterInputEnd,
                $run[
                    'first_tts_audio_chunk_relative_to_input_end_ms'
                ] ?? null,
            );

            $this->appendNumber(
                $rawPlaybackAfterInputEnd,
                $run[
                    'raw_playback_first_audible_audio_relative_to_input_end_ms'
                ] ?? null,
            );

            $this->appendNumber(
                $audibleAvailableAfterInputEnd,
                $run[
                    'first_audible_audio_available_relative_to_input_end_ms'
                ] ?? null,
            );

            $this->appendNumber(
                $ttsDuration,
                $run[
                    'tts_duration_ms'
                ] ?? null,
            );

            $this->appendNumber(
                $ttsCompletedAfterInputEnd,
                $run[
                    'tts_completed_relative_to_input_end_ms'
                ] ?? null,
            );

            $this->appendNumber(
                $totalDuration,
                $run[
                    'total_duration_ms'
                ] ?? null,
            );
        }

        return [
            'successful_runs' => $successfulRuns,

            'failed_runs' => $failedRuns,

            'stages' => [
                'speech_to_text' => $this->reliability(
                    successful: $sttSuccessful,
                    attempted: $sttAttempted,
                ),

                'translation' => $this->reliability(
                    successful: $translationSuccessful,
                    attempted: $translationAttempted,
                ),

                'text_to_speech' => $this->reliability(
                    successful: $ttsSuccessful,
                    attempted: $ttsAttempted,
                ),
            ],

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
                'eligible_runs' => $audibleEligibleRuns,

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

                'stt_completed_relative_to_input_end_ms' => $this->summarize(
                    $sttAfterInputEnd,
                ),

                'translation_latency_ms' => $this->summarize(
                    $translationLatency,
                ),

                'translation_completed_relative_to_input_end_ms' => $this->summarize(
                    $translationCompletedAfterInputEnd,
                ),

                'tts_first_audio_chunk_latency_ms' => $this->summarize(
                    $ttsFirstAudioChunkLatency,
                ),

                'tts_first_audible_available_latency_ms' => $this->summarize(
                    $ttsFirstAudibleLatency,
                ),

                'first_tts_audio_chunk_relative_to_input_end_ms' => $this->summarize(
                    $firstAudioChunkAfterInputEnd,
                ),

                'raw_playback_first_audible_audio_relative_to_input_end_ms' => $this->summarize(
                    $rawPlaybackAfterInputEnd,
                ),

                'first_audible_audio_available_relative_to_input_end_ms' => $this->summarize(
                    $audibleAvailableAfterInputEnd,
                ),

                'stop_to_audio_ready_ms' => $this->summarize(
                    $stopToAudioReady,
                ),

                'tts_duration_ms' => $this->summarize(
                    $ttsDuration,
                ),

                'tts_completed_relative_to_input_end_ms' => $this->summarize(
                    $ttsCompletedAfterInputEnd,
                ),

                'total_duration_ms' => $this->summarize(
                    $totalDuration,
                ),
            ],
        ];
    }

    /**
     * @return array{
     *     attempted: int,
     *     successful: int,
     *     failed: int,
     *     success_rate_percent: float|null
     * }
     */
    private function reliability(
        int $successful,
        int $attempted,
    ): array {
        return [
            'attempted' => $attempted,

            'successful' => $successful,

            'failed' => max(
                0,
                $attempted - $successful,
            ),

            'success_rate_percent' => $attempted > 0
                    ? round(
                        (
                            $successful
                            / $attempted
                        ) * 100,
                        2,
                    )
                    : null,
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
