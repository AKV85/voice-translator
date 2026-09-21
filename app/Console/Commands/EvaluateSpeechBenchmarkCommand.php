<?php

namespace App\Console\Commands;

use App\Services\Benchmark\SpeechBenchmarkEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;

class EvaluateSpeechBenchmarkCommand extends Command
{
    protected $signature = 'benchmark:speech:evaluate
        {profile : Speech benchmark profile name}';

    protected $description =
        'Evaluate an existing speech benchmark result set';

    /**
     * @throws JsonException
     */
    public function handle(
        SpeechBenchmarkEvaluator $evaluator,
    ): int {
        $profile = (string) $this->argument('profile');

        $resultsPath = config(
            'benchmarks.speech.results_path',
        );

        if (! is_string($resultsPath)) {
            throw new RuntimeException(
                'Speech benchmark results path is not configured.',
            );
        }

        $filePath =
            "{$resultsPath}/{$profile}/results.json";

        if (! File::exists($filePath)) {
            $this->error(
                "Speech benchmark results not found: {$profile}"
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
                'Invalid speech benchmark result file.',
            );
        }

        $benchmark = $evaluator->evaluate($benchmark);

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

        $summary =
            $benchmark['evaluation']['summary'];

        $this->info(
            "Evaluated speech benchmark: {$profile}"
        );

        $this->line(
            "Successful: {$summary['successful_count']}/"
            ."{$summary['fixture_count']}"
        );

        $this->line(
            'Average raw WER: '
            .$this->formatWer(
                $summary['average_raw_wer'],
            )
        );

        $this->line(
            'Average normalized WER: '
            .$this->formatWer(
                $summary['average_normalized_wer'],
            )
        );

        $this->line(
            'Content mismatches: '
            ."{$summary['content_mismatch_count']}"
        );

        $this->line("Results: {$filePath}");

        return self::SUCCESS;
    }

    private function formatWer(?float $wer): string
    {
        if ($wer === null) {
            return 'n/a';
        }

        return number_format(
            $wer * 100,
            2,
        ).'%';
    }
}
