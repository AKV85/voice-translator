<?php

namespace App\Console\Commands;

use App\Services\Benchmark\StreamingSpeechBenchmarkEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;

class EvaluateStreamingSpeechBenchmarkCommand extends Command
{
    protected $signature = 'benchmark:speech:stream:evaluate
        {profile : Streaming speech benchmark profile name}';

    protected $description =
        'Evaluate an existing streaming speech benchmark result set';

    /**
     * @throws JsonException
     */
    public function handle(
        StreamingSpeechBenchmarkEvaluator $evaluator,
    ): int {
        $profile =
            (string) $this->argument(
                'profile',
            );

        $resultsPath = config(
            'benchmarks.speech.results_path',
        );

        if (! is_string($resultsPath)) {
            throw new RuntimeException(
                'Speech benchmark results path is not configured.',
            );
        }

        $filePath =
            "{$resultsPath}/{$profile}/streaming-results.json";

        if (! File::exists($filePath)) {
            $this->error(
                "Streaming speech benchmark results not found: {$profile}"
            );

            return self::FAILURE;
        }

        $benchmark = json_decode(
            File::get($filePath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (! is_array($benchmark)) {
            throw new RuntimeException(
                'Invalid streaming speech benchmark result file.',
            );
        }

        $benchmark =
            $evaluator->evaluate(
                $benchmark,
            );

        File::put(
            $filePath,
            json_encode(
                $benchmark,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR,
            ).PHP_EOL,
        );

        $evaluation =
            $benchmark['evaluation']
            ?? null;

        if (! is_array($evaluation)) {
            throw new RuntimeException(
                'Streaming speech benchmark evaluation is missing.',
            );
        }

        $summary =
            $evaluation['summary']
            ?? null;

        $streamingLatency =
            $evaluation['streaming_latency']
            ?? null;

        if (
            ! is_array($summary)
            || ! is_array($streamingLatency)
        ) {
            throw new RuntimeException(
                'Streaming speech benchmark summary is invalid.',
            );
        }

        $this->info(
            "Evaluated streaming speech benchmark: {$profile}"
        );

        $this->line(
            'Successful: '
            .($summary['successful_count'] ?? 0)
            .'/'
            .($summary['fixture_count'] ?? 0)
        );

        $this->line(
            'Average raw WER: '
            .$this->formatWer(
                $summary['average_raw_wer']
                ?? null,
            )
        );

        $this->line(
            'Average normalized WER: '
            .$this->formatWer(
                $summary['average_normalized_wer']
                ?? null,
            )
        );

        $this->line(
            'Exact matches: '
            .($summary['exact_match_count'] ?? 0)
        );

        $this->line(
            'Normalization only: '
            .(
                $summary[
                    'normalization_only_count'
                ]
                ?? 0
            )
        );

        $this->line(
            'Content mismatches: '
            .(
                $summary[
                    'content_mismatch_count'
                ]
                ?? 0
            )
        );

        $this->newLine();

        $this->printLatencySummary(
            label: 'Time to first transcript',
            summary: $streamingLatency[
                    'time_to_first_transcript_ms'
                ] ?? null,
        );

        $this->printLatencySummary(
            label: 'Finalization latency',
            summary: $streamingLatency[
                    'finalization_latency_ms'
                ] ?? null,
        );

        $this->printLatencySummary(
            label: 'Estimated provider setup',
            summary: $streamingLatency[
                    'estimated_provider_setup_ms'
                ] ?? null,
        );

        $this->line(
            "Results: {$filePath}"
        );

        return self::SUCCESS;
    }

    private function formatWer(
        mixed $wer,
    ): string {
        if (! is_numeric($wer)) {
            return 'n/a';
        }

        return number_format(
            (float) $wer * 100,
            2,
        ).'%';
    }

    private function printLatencySummary(
        string $label,
        mixed $summary,
    ): void {
        if (! is_array($summary)) {
            $this->line(
                "{$label}: n/a"
            );

            return;
        }

        $this->line(
            $label.': '
            .'avg '
            .$this->formatMilliseconds(
                $summary['average']
                ?? null,
            )
            .', median '
            .$this->formatMilliseconds(
                $summary['median']
                ?? null,
            )
            .', p95 '
            .$this->formatMilliseconds(
                $summary['p95']
                ?? null,
            )
            .', max '
            .$this->formatMilliseconds(
                $summary['max']
                ?? null,
            )
        );
    }

    private function formatMilliseconds(
        mixed $value,
    ): string {
        if (! is_numeric($value)) {
            return 'n/a';
        }

        return number_format(
            (float) $value,
            2,
            '.',
            '',
        ).' ms';
    }
}
