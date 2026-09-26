<?php

namespace App\Console\Commands;

use App\Services\Benchmark\TranslationBenchmarkEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;

final class EvaluateTranslationBenchmarkCommand extends Command
{
    protected $signature =
        'benchmark:translation:evaluate
        {profile : Translation benchmark profile}
        {pair : Language pair, for example ru-en or en-ru}';

    protected $description =
        'Evaluate translation benchmark results';

    /**
     * @throws JsonException
     */
    public function handle(
        TranslationBenchmarkEvaluator $evaluator,
    ): int {
        $profile =
            $this->argument('profile');

        $pair =
            $this->argument('pair');

        if ($profile === '' || $pair === '') {
            throw new RuntimeException(
                'Translation benchmark profile and language pair are required.',
            );
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
                'Translation benchmark paths are not configured.',
            );
        }

        $filePath =
            "{$resultsPath}/{$profile}/results-{$pair}.json";

        if (! File::exists($filePath)) {
            $this->error(
                "Translation benchmark results not found: {$filePath}"
            );

            return self::FAILURE;
        }

        if (! File::exists($datasetPath)) {
            $this->error(
                "Translation benchmark dataset not found: {$datasetPath}"
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
                'Translation benchmark result file is invalid.',
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
                'Translation evaluation is invalid.',
            );
        }

        $criticalElements =
            $evaluation['critical_elements']
            ?? [];

        $latency =
            $evaluation['latency']
            ?? [];

        $coldStart =
            is_array($latency)
                ? ($latency['cold_start'] ?? null)
                : null;

        $warm =
            is_array($latency)
                ? ($latency['warm'] ?? [])
                : [];

        $this->info(
            "Translation benchmark evaluation: {$profile} / {$pair}"
        );

        $this->newLine();

        $this->line(
            'Successful: '
            .$evaluation['successful']
            .'/'
            .(
                $evaluation['successful']
                + $evaluation['failed']
            )
        );

        if (is_array($criticalElements)) {
            $this->line(
                'Critical elements: '
                .$criticalElements['passed']
                .'/'
                .$criticalElements['total']
                .' ('
                .$criticalElements[
                    'pass_rate_percent'
                ]
                .'%)'
            );

            $this->line(
                'Fixtures with missing critical elements: '
                .$criticalElements[
                    'fixtures_with_missing_critical_elements'
                ]
            );
        }

        if (is_array($coldStart)) {
            $coldLatency =
                $coldStart['latency_ms']
                ?? null;

            if (
                is_int($coldLatency)
                || is_float($coldLatency)
            ) {
                $this->line(
                    'Cold start: '
                    .number_format(
                        (float) $coldLatency,
                        2,
                        '.',
                        '',
                    )
                    .' ms'
                );
            }
        }

        if (is_array($warm)) {
            $this->newLine();

            $this->line(
                'Warm latency:'
            );

            $this->line(
                '  avg: '
                .$this->formatLatency(
                    $warm['avg_ms']
                    ?? null,
                )
            );

            $this->line(
                '  median: '
                .$this->formatLatency(
                    $warm['median_ms']
                    ?? null,
                )
            );

            $this->line(
                '  p95: '
                .$this->formatLatency(
                    $warm['p95_ms']
                    ?? null,
                )
            );

            $this->line(
                '  min: '
                .$this->formatLatency(
                    $warm['min_ms']
                    ?? null,
                )
            );

            $this->line(
                '  max: '
                .$this->formatLatency(
                    $warm['max_ms']
                    ?? null,
                )
            );
        }

        $missingResults = collect(
            $evaluated['results']
            ?? [],
        )->filter(
            static function (
                mixed $result,
            ): bool {
                if (! is_array($result)) {
                    return false;
                }

                $resultEvaluation =
                    $result['evaluation']
                    ?? null;

                if (
                    ! is_array(
                        $resultEvaluation
                    )
                ) {
                    return false;
                }

                $missing =
                    $resultEvaluation[
                        'missing_critical_elements'
                    ]
                    ?? [];

                return is_array($missing)
                    && $missing !== [];
            },
        );

        if ($missingResults->isNotEmpty()) {
            $this->newLine();

            $this->warn(
                'Missing critical elements:'
            );

            foreach ($missingResults as $result) {
                if (! is_array($result)) {
                    continue;
                }

                $resultEvaluation =
                    $result['evaluation']
                    ?? [];

                $missing =
                    is_array($resultEvaluation)
                        ? (
                            $resultEvaluation[
                                'missing_critical_elements'
                            ]
                            ?? []
                        )
                        : [];

                if (! is_array($missing)) {
                    continue;
                }

                $this->line(
                    '  '
                    .($result['phrase_id'] ?? 'unknown')
                    .': '
                    .implode(
                        ', ',
                        $missing,
                    )
                );
            }
        }

        $this->newLine();

        $this->line(
            "Results updated: {$filePath}"
        );

        return self::SUCCESS;
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
}
