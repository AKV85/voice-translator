<?php

namespace App\Console\Commands;

use App\Contracts\RealtimeTranslationProvider;
use App\Services\Benchmark\RealtimeTranslationBenchmarkRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

class BenchmarkRealtimeTranslationCommand extends Command
{
    protected $signature =
        'benchmark:translation:realtime
        {--provider= : Realtime translation benchmark provider profile}
        {--pair= : Language pair, for example ru-en or en-ru}
        {--phrase= : Run only one speech benchmark phrase by ID}
        {--runs= : Number of runs per fixture}';

    protected $description =
        'Run canonical realtime speech translation benchmark fixtures';

    public function handle(
        RealtimeTranslationBenchmarkRunner $runner,
    ): int {
        $profileName =
            (string) $this->option(
                'provider',
            );

        if ($profileName === '') {
            $this->error(
                'Realtime translation benchmark provider is required.',
            );

            return self::FAILURE;
        }

        $languagePair =
            $this->option(
                'pair',
            );

        if ($languagePair === '') {
            $languagePair = null;
        }

        $phraseId =
            $this->option(
                'phrase',
            );

        if ($phraseId === '') {
            $phraseId = null;
        }

        $profile = config(
            "benchmarks.translation.realtime.providers.{$profileName}",
        );

        if (! is_array($profile)) {
            $this->error(
                "Unknown realtime translation benchmark provider: {$profileName}",
            );

            return self::FAILURE;
        }

        $contract =
            $profile['contract']
            ?? null;

        $providerName =
            $profile['provider']
            ?? null;

        $modelName =
            $profile['model']
            ?? null;

        $configOverrides =
            $profile['config']
            ?? [];

        if (
            ! is_string($contract)
            || ! is_string($providerName)
            || ! is_string($modelName)
            || ! is_array($configOverrides)
        ) {
            throw new RuntimeException(
                "Invalid realtime translation benchmark profile: {$profileName}",
            );
        }

        config(
            $configOverrides,
        );

        app()->forgetInstance(
            $contract,
        );

        $provider =
            app(
                $contract,
            );

        if (
            ! $provider
            instanceof RealtimeTranslationProvider
        ) {
            throw new RuntimeException(
                "{$contract} must resolve to "
                .RealtimeTranslationProvider::class
                .'.',
            );
        }

        $speechDatasetPath = config(
            'benchmarks.speech.dataset_path',
        );

        $translationDatasetPath = config(
            'benchmarks.translation.dataset_path',
        );

        $resultsPath = config(
            'benchmarks.translation.results_path',
        );

        $chunkDurationMs = config(
            'benchmarks.translation.realtime.chunk_duration_ms',
            100,
        );

        if (
            ! is_string($speechDatasetPath)
            || ! is_string($translationDatasetPath)
            || ! is_string($resultsPath)
        ) {
            throw new RuntimeException(
                'Realtime translation benchmark paths are not configured.',
            );
        }

        if (
            ! is_int($chunkDurationMs)
            || $chunkDurationMs <= 0
        ) {
            throw new RuntimeException(
                'Realtime translation benchmark chunk duration is invalid.',
            );
        }

        $runsOption =
            $this->option(
                'runs',
            );

        if (
            $runsOption === null
            || $runsOption === ''
        ) {
            $runsPerFixture = config(
                'benchmarks.translation.realtime.runs_per_fixture',
                3,
            );
        } elseif (is_numeric($runsOption)) {
            $runsPerFixture =
                (int) $runsOption;
        } else {
            $runsPerFixture = 0;
        }

        if (
            ! is_int($runsPerFixture)
            || $runsPerFixture <= 0
        ) {
            $this->error(
                'Runs per fixture must be greater than zero.',
            );

            return self::FAILURE;
        }

        $this->info(
            "Running realtime translation benchmark profile: {$profileName}",
        );

        $this->line(
            "Provider: {$providerName}",
        );

        $this->line(
            "Model: {$modelName}",
        );

        $this->line(
            "Chunk duration: {$chunkDurationMs} ms",
        );

        $this->line(
            "Runs per fixture: {$runsPerFixture}",
        );

        if ($languagePair !== null) {
            $this->line(
                "Language pair: {$languagePair}",
            );
        }

        if ($phraseId !== null) {
            $this->line(
                "Phrase: {$phraseId}",
            );
        }

        $this->newLine();

        $benchmark =
            $runner->run(
                provider: $provider,

                profileName: $profileName,

                providerName: $providerName,

                modelName: $modelName,

                speechDatasetPath: $speechDatasetPath,

                translationDatasetPath: $translationDatasetPath,

                chunkDurationMs: $chunkDurationMs,

                runsPerFixture: $runsPerFixture,

                languagePair: $languagePair,

                phraseId: $phraseId,

                onRunStarted: function (
                    array $progress,
                ): void {
                    $fixtureIndex =
                        $progress[
                            'fixture_index'
                        ];

                    $fixtureCount =
                        $progress[
                            'fixture_count'
                        ];

                    $phraseId =
                        $progress[
                            'phrase_id'
                        ];

                    $runNumber =
                        $progress[
                            'run_number'
                        ];

                    $runsPerFixture =
                        $progress[
                            'runs_per_fixture'
                        ];

                    if ($runNumber === 1) {
                        if ($fixtureIndex > 1) {
                            $this->newLine();
                        }

                        $this->line(
                            "[{$fixtureIndex}/{$fixtureCount}] {$phraseId}",
                        );
                    }

                    $this->output->write(
                        "  run {$runNumber}/{$runsPerFixture} ... ",
                    );
                },

                onRunCompleted: function (
                    array $progress,
                ): void {
                    $result =
                        $progress['result']
                        ?? [];

                    $wallDurationMs =
                        $progress[
                            'wall_duration_ms'
                        ] ?? null;

                    $duration =
                        is_int($wallDurationMs)
                        || is_float($wallDurationMs)
                            ? number_format(
                                (
                                    (float) $wallDurationMs
                                    / 1000
                                ),
                                2,
                                '.',
                                '',
                            ).' s'
                            : 'n/a';

                    $successful =
                        is_array($result)
                        && (
                            $result[
                                'success'
                            ] ?? false
                        ) === true;

                    if ($successful) {
                        $this->line(
                            "OK  {$duration}",
                        );

                        return;
                    }

                    $failure =
                        is_array($result)
                            ? $this->failureSummary(
                                $result,
                            )
                            : 'unknown error';

                    $this->line(
                        "FAIL  {$duration}  {$failure}",
                    );
                },
            );

        $profileResultsPath =
            "{$resultsPath}/{$profileName}";

        File::ensureDirectoryExists(
            $profileResultsPath,
        );

        $outputFilename =
            $this->outputFilename(
                languagePair: $languagePair,

                phraseId: $phraseId,
            );

        $outputPath =
            "{$profileResultsPath}/{$outputFilename}";

        File::put(
            $outputPath,
            json_encode(
                $benchmark,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR,
            ).PHP_EOL,
        );

        $runs = collect(
            $benchmark['results'],
        )->flatMap(
            static fn (array $result): array => is_array(
                $result['runs']
                ?? null,
            )
                    ? $result['runs']
                    : [],
        );

        $successful =
            $runs
                ->where(
                    'success',
                    true,
                )
                ->count();

        $total =
            $runs->count();

        $this->newLine();

        $this->info(
            "Completed: {$successful}/{$total} runs successful.",
        );

        $this->line(
            "Results: {$outputPath}",
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function failureSummary(
        array $result,
    ): string {
        $error =
            $result['error']
            ?? 'unknown error';

        $message =
            $result[
                'previous_error_message'
            ]
            ?? $result[
                'error_message'
            ]
            ?? null;

        $errorName =
            is_string($error)
                ? $this->shortClassName(
                    $error,
                )
                : 'unknown error';

        if (
            ! is_string($message)
            || trim($message) === ''
        ) {
            return $errorName;
        }

        $message =
            preg_replace(
                '/\s+/',
                ' ',
                trim($message),
            ) ?? trim($message);

        if (mb_strlen($message) > 140) {
            $message =
                mb_substr(
                    $message,
                    0,
                    137,
                )
                .'...';
        }

        return "{$errorName}: {$message}";
    }

    private function shortClassName(
        string $class,
    ): string {
        $position =
            strrpos(
                $class,
                '\\',
            );

        if ($position === false) {
            return $class;
        }

        return substr(
            $class,
            $position + 1,
        );
    }

    private function outputFilename(
        ?string $languagePair,
        ?string $phraseId,
    ): string {
        if ($phraseId !== null) {
            return 'realtime-results-'
                .$this->safeFilenamePart(
                    $phraseId,
                )
                .'.json';
        }

        if ($languagePair !== null) {
            return 'realtime-results-'
                .$this->safeFilenamePart(
                    $languagePair,
                )
                .'.json';
        }

        return 'realtime-results.json';
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
                'Unable to create realtime benchmark result filename.',
            );
        }

        return $safe;
    }
}
