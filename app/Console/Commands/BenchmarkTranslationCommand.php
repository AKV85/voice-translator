<?php

namespace App\Console\Commands;

use App\Contracts\TranslationProvider;
use App\Services\Benchmark\TranslationBenchmarkRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

class BenchmarkTranslationCommand extends Command
{
    protected $signature = 'benchmark:translation
        {--provider= : Translation benchmark provider profile}
        {--pair= : Language pair, for example ru-en or en-ru}';

    protected $description =
        'Run translation benchmark fixtures';

    public function handle(
        TranslationBenchmarkRunner $runner,
    ): int {
        $profileName =
            $this->option('provider');

        $languagePair =
            $this->option('pair');

        if ($profileName === null || $profileName === '') {
            $this->error(
                'Translation benchmark provider is required.'
            );

            return self::FAILURE;
        }

        if ($languagePair === '') {
            $languagePair = null;
        }

        $profile = config(
            "benchmarks.translation.providers.{$profileName}"
        );

        if (! is_array($profile)) {
            $this->error(
                "Unknown translation benchmark provider: {$profileName}"
            );

            return self::FAILURE;
        }

        $contract =
            $profile['contract'] ?? null;

        $providerName =
            $profile['provider'] ?? null;

        $modelName =
            $profile['model'] ?? null;

        $configOverrides =
            $profile['config'] ?? [];

        if (
            ! is_string($contract)
            || ! is_string($providerName)
            || ! is_string($modelName)
            || ! is_array($configOverrides)
        ) {
            throw new RuntimeException(
                "Invalid translation benchmark profile: {$profileName}"
            );
        }

        config(
            $configOverrides,
        );

        $provider =
            app($contract);

        if (
            ! $provider
                instanceof TranslationProvider
        ) {
            throw new RuntimeException(
                "{$contract} must implement "
                .TranslationProvider::class
                .'.'
            );
        }

        $datasetPath = config(
            'benchmarks.translation.dataset_path'
        );

        $resultsPath = config(
            'benchmarks.translation.results_path'
        );

        if (
            ! is_string($datasetPath)
            || ! is_string($resultsPath)
        ) {
            throw new RuntimeException(
                'Translation benchmark paths are not configured.'
            );
        }

        $this->info(
            "Running translation benchmark profile: {$profileName}"
        );

        $this->line(
            "Provider: {$providerName}"
        );

        $this->line(
            "Model: {$modelName}"
        );

        if ($languagePair !== null) {
            $this->line(
                "Language pair: {$languagePair}"
            );
        }

        $benchmark = $runner->run(
            provider: $provider,
            profileName: $profileName,
            providerName: $providerName,
            modelName: $modelName,
            datasetPath: $datasetPath,
            languagePair: $languagePair,
        );

        $profileResultsPath =
            "{$resultsPath}/{$profileName}";

        File::ensureDirectoryExists(
            $profileResultsPath,
        );

        $filename = $languagePair === null
            ? 'results.json'
            : "results-{$languagePair}.json";

        $outputPath =
            "{$profileResultsPath}/{$filename}";

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

        $successful = collect(
            $benchmark['results'],
        )
            ->where(
                'success',
                true,
            )
            ->count();

        $total = count(
            $benchmark['results'],
        );

        $this->newLine();

        $this->info(
            "Completed: {$successful}/{$total} successful."
        );

        $this->line(
            "Results: {$outputPath}"
        );

        return self::SUCCESS;
    }
}
