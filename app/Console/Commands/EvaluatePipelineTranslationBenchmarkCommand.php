<?php

namespace App\Console\Commands;

use App\Services\Benchmark\PipelineTranslationBenchmarkEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;

final class EvaluatePipelineTranslationBenchmarkCommand extends Command
{
    protected $signature =
        'benchmark:translation:pipeline:evaluate
        {profile : Pipeline translation benchmark profile}
        {--pair= : Language pair, for example ru-en or en-ru}
        {--phrase= : Speech benchmark phrase ID}';

    protected $description =
        'Evaluate STT to translation to TTS benchmark results';

    /**
     * @throws JsonException
     */
    public function handle(
        PipelineTranslationBenchmarkEvaluator $evaluator,
    ): int {
        $profile =
            $this->argument(
                'profile',
            );

        if ($profile === '') {
            throw new RuntimeException(
                'Pipeline translation benchmark profile is required.',
            );
        }

        $pair =
            $this->option(
                'pair',
            );

        if ($pair === '') {
            $pair = null;
        }

        $phraseId =
            $this->option(
                'phrase',
            );

        if ($phraseId === '') {
            $phraseId = null;
        }

        $resultsPath = config(
            'benchmarks.translation.results_path',
        );

        $datasetPath = config(
            'benchmarks.translation.dataset_path',
        );

        if (
            ! is_string($resultsPath)
            || ! is_string($datasetPath)
        ) {
            throw new RuntimeException(
                'Pipeline translation benchmark paths are not configured.',
            );
        }

        $filename =
            $this->resultFilename(
                pair: $pair,
                phraseId: $phraseId,
            );

        $filePath =
            "{$resultsPath}/{$profile}/{$filename}";

        if (! File::exists($filePath)) {
            $this->error(
                "Pipeline translation benchmark results not found: {$filePath}",
            );

            return self::FAILURE;
        }

        if (! File::exists($datasetPath)) {
            $this->error(
                "Translation benchmark dataset not found: {$datasetPath}",
            );

            return self::FAILURE;
        }

        $benchmark = json_decode(
            File::get($filePath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $dataset = json_decode(
            File::get($datasetPath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (! is_array($benchmark)) {
            throw new RuntimeException(
                'Pipeline translation benchmark result file is invalid.',
            );
        }

        if (! is_array($dataset)) {
            throw new RuntimeException(
                'Translation benchmark dataset is invalid.',
            );
        }

        $evaluated =
            $evaluator->evaluate(
                benchmark: $benchmark,
                dataset: $dataset,
            );

        File::put(
            $filePath,
            json_encode(
                $evaluated,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR,
            ).PHP_EOL,
        );

        $evaluation =
            $evaluated['evaluation']
            ?? null;

        if (! is_array($evaluation)) {
            throw new RuntimeException(
                'Pipeline translation evaluation is invalid.',
            );
        }

        $successful =
            (int) (
                $evaluation[
                    'successful_runs'
                ] ?? 0
            );

        $failed =
            (int) (
                $evaluation[
                    'failed_runs'
                ] ?? 0
            );

        $manualReviewRequired =
            (int) (
                $evaluation[
                    'manual_review_required_runs'
                ] ?? 0
            );

        $stages =
            $evaluation['stages']
            ?? [];

        $criticalElements =
            $evaluation[
                'critical_elements'
            ] ?? [];

        $audioOutput =
            $evaluation[
                'audio_output'
            ] ?? [];

        $latency =
            $evaluation['latency']
            ?? [];

        $this->info(
            "Pipeline translation benchmark evaluation: {$profile}",
        );

        if ($phraseId !== null) {
            $this->line(
                "Phrase: {$phraseId}",
            );
        } elseif ($pair !== null) {
            $this->line(
                "Language pair: {$pair}",
            );
        }

        $this->newLine();

        $this->line(
            'Successful runs: '
            .$successful
            .'/'
            .($successful + $failed),
        );

        if (is_array($stages)) {
            $this->printStageReliability(
                title: 'STT successful',
                stage: $stages[
                    'speech_to_text'
                ] ?? null,
            );

            $this->printStageReliability(
                title: 'Translation successful',
                stage: $stages[
                    'translation'
                ] ?? null,
            );

            $this->printStageReliability(
                title: 'TTS successful',
                stage: $stages[
                    'text_to_speech'
                ] ?? null,
            );
        }

        $this->line(
            'Manual review required: '
            .$manualReviewRequired,
        );

        if (is_array($criticalElements)) {
            $passed =
                (int) (
                    $criticalElements[
                        'passed'
                    ] ?? 0
                );

            $total =
                (int) (
                    $criticalElements[
                        'total'
                    ] ?? 0
                );

            $passRate =
                $criticalElements[
                    'pass_rate_percent'
                ] ?? null;

            $this->line(
                'Critical elements: '
                .$passed
                .'/'
                .$total
                .' ('
                .$this->formatPercent(
                    $passRate,
                )
                .')',
            );

            $this->line(
                'Runs with missing critical elements: '
                .(
                    $criticalElements[
                        'runs_with_missing_critical_elements'
                    ] ?? 0
                ),
            );
        }

        if (is_array($audioOutput)) {
            $audibleDetected =
                (int) (
                    $audioOutput[
                        'audible_detected_runs'
                    ] ?? 0
                );

            $audibleEligible =
                (int) (
                    $audioOutput[
                        'eligible_runs'
                    ] ?? 0
                );

            $audibleRate =
                $audioOutput[
                    'audible_detection_rate_percent'
                ] ?? null;

            $this->line(
                'Audible audio detected: '
                .$audibleDetected
                .'/'
                .$audibleEligible
                .' ('
                .$this->formatPercent(
                    $audibleRate,
                )
                .')',
            );
        }

        if (is_array($latency)) {
            $this->printLatencySummary(
                title: 'STT after STOP',
                summary: $latency[
                    'stt_completed_relative_to_input_end_ms'
                ] ?? null,
            );

            $this->printLatencySummary(
                title: 'Translation latency',
                summary: $latency[
                    'translation_latency_ms'
                ] ?? null,
            );

            $this->printLatencySummary(
                title: 'TTS -> audible',
                summary: $latency[
                    'tts_first_audible_available_latency_ms'
                ] ?? null,
            );

            $this->printLatencySummary(
                title: 'STOP -> audio ready',
                summary: $latency[
                    'stop_to_audio_ready_ms'
                ] ?? null,
            );

            $this->printLatencySummary(
                title: 'Raw playback audible relative to input end',
                summary: $latency[
                    'raw_playback_first_audible_audio_relative_to_input_end_ms'
                ] ?? null,
            );

            $this->printLatencySummary(
                title: 'TTS total',
                summary: $latency[
                    'tts_duration_ms'
                ] ?? null,
            );

            $this->printLatencySummary(
                title: 'Total pipeline',
                summary: $latency[
                    'total_duration_ms'
                ] ?? null,
            );
        }

        $this->printMissingCriticalElements(
            $evaluated,
        );

        $this->newLine();

        $this->line(
            "Results updated: {$filePath}",
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>|mixed  $stage
     */
    private function printStageReliability(
        string $title,
        mixed $stage,
    ): void {
        if (! is_array($stage)) {
            return;
        }

        $successful =
            (int) (
                $stage[
                    'successful'
                ] ?? 0
            );

        $attempted =
            (int) (
                $stage[
                    'attempted'
                ] ?? 0
            );

        $rate =
            $stage[
                'success_rate_percent'
            ] ?? null;

        $this->line(
            "{$title}: "
            .$successful
            .'/'
            .$attempted
            .' ('
            .$this->formatPercent(
                $rate,
            )
            .')',
        );
    }

    private function resultFilename(
        ?string $pair,
        ?string $phraseId,
    ): string {
        if ($phraseId !== null) {
            return 'pipeline-results-'
                .$this->safeFilenamePart(
                    $phraseId,
                )
                .'.json';
        }

        if ($pair !== null) {
            return 'pipeline-results-'
                .$this->safeFilenamePart(
                    $pair,
                )
                .'.json';
        }

        return 'pipeline-results.json';
    }

    private function safeFilenamePart(
        string $value,
    ): string {
        $safe =
            preg_replace(
                '/[^A-Za-z0-9._-]/',
                '_',
                $value,
            );

        if (
            ! is_string($safe)
            || $safe === ''
        ) {
            throw new RuntimeException(
                'Unable to create pipeline benchmark result filename.',
            );
        }

        return $safe;
    }

    private function printLatencySummary(
        string $title,
        mixed $summary,
    ): void {
        if (! is_array($summary)) {
            return;
        }

        $this->newLine();

        $this->line(
            "{$title}:",
        );

        $this->line(
            '  median: '
            .$this->formatLatency(
                $summary[
                    'median_ms'
                ] ?? null,
            ),
        );

        $this->line(
            '  avg: '
            .$this->formatLatency(
                $summary[
                    'avg_ms'
                ] ?? null,
            ),
        );

        $this->line(
            '  min: '
            .$this->formatLatency(
                $summary[
                    'min_ms'
                ] ?? null,
            ),
        );

        $this->line(
            '  max: '
            .$this->formatLatency(
                $summary[
                    'max_ms'
                ] ?? null,
            ),
        );

        $this->line(
            '  p95: '
            .$this->formatLatency(
                $summary[
                    'p95_ms'
                ] ?? null,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $evaluated
     */
    private function printMissingCriticalElements(
        array $evaluated,
    ): void {
        $missingRows = [];

        $results =
            $evaluated['results']
            ?? [];

        if (! is_array($results)) {
            return;
        }

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $phraseId =
                $result['phrase_id']
                ?? 'unknown';

            $runs =
                $result['runs']
                ?? [];

            if (! is_array($runs)) {
                continue;
            }

            foreach ($runs as $run) {
                if (! is_array($run)) {
                    continue;
                }

                $evaluation =
                    $run['evaluation']
                    ?? [];

                if (! is_array($evaluation)) {
                    continue;
                }

                $missing =
                    $evaluation[
                        'missing_critical_elements'
                    ] ?? [];

                if (
                    ! is_array($missing)
                    || $missing === []
                ) {
                    continue;
                }

                $runNumber =
                    $run['run']
                    ?? '?';

                $missingRows[] =
                    $phraseId
                    .' run '
                    .$runNumber
                    .': '
                    .implode(
                        ', ',
                        $missing,
                    );
            }
        }

        if ($missingRows === []) {
            return;
        }

        $this->newLine();

        $this->warn(
            'Missing critical elements:',
        );

        foreach ($missingRows as $row) {
            $this->line(
                "  {$row}",
            );
        }
    }

    private function formatLatency(
        mixed $value,
    ): string {
        if (
            ! is_int($value)
            && ! is_float($value)
        ) {
            return 'n/a';
        }

        return number_format(
            (float) $value,
            2,
            '.',
            '',
        ).' ms';
    }

    private function formatPercent(
        mixed $value,
    ): string {
        if (
            ! is_int($value)
            && ! is_float($value)
        ) {
            return 'n/a';
        }

        return number_format(
            (float) $value,
            2,
            '.',
            '',
        ).'%';
    }
}
