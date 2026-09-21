<?php

namespace App\Console\Commands;

use App\Contracts\SpeechToTextProvider;
use App\Services\Benchmark\SpeechBenchmarkRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

class BenchmarkSpeechCommand extends Command
{
    protected $signature = 'benchmark:speech
        {--provider=google-short : Speech benchmark provider profile}';

    protected $description = 'Run canonical speech benchmark fixtures';

    public function handle(SpeechBenchmarkRunner $runner): int
    {
        $profileName = (string) $this->option('provider');

        $profile = config(
            "benchmarks.speech.providers.{$profileName}"
        );

        if (! is_array($profile)) {
            $this->error(
                "Unknown speech benchmark provider: {$profileName}"
            );

            return self::FAILURE;
        }

        $contract = $profile['contract'] ?? null;
        $providerName = $profile['provider'] ?? null;
        $modelName = $profile['model'] ?? null;
        $configOverrides = $profile['config'] ?? [];

        if (
            ! is_string($contract)
            || ! is_string($providerName)
            || ! is_string($modelName)
            || ! is_array($configOverrides)
        ) {
            throw new RuntimeException(
                "Invalid speech benchmark profile: {$profileName}"
            );
        }

        config($configOverrides);

        app()->forgetInstance($contract);

        $provider = app($contract);

        if (! $provider instanceof SpeechToTextProvider) {
            throw new RuntimeException(
                "{$contract} must resolve to SpeechToTextProvider."
            );
        }

        $datasetPath = config('benchmarks.speech.dataset_path');
        $resultsPath = config('benchmarks.speech.results_path');

        if (! is_string($datasetPath) || ! is_string($resultsPath)) {
            throw new RuntimeException(
                'Speech benchmark paths are not configured.'
            );
        }

        $this->info(
            "Running speech benchmark profile: {$profileName}"
        );

        $this->line(
            "Provider: {$providerName}"
        );

        $this->line(
            "Model: {$modelName}"
        );

        $benchmark = $runner->run(
            provider: $provider,
            profileName: $profileName,
            providerName: $providerName,
            modelName: $modelName,
            datasetPath: $datasetPath,
        );

        $profileResultsPath = "{$resultsPath}/{$profileName}";

        File::ensureDirectoryExists($profileResultsPath);

        $outputPath = "{$profileResultsPath}/results.json";

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

        $successful = collect($benchmark['results'])
            ->where('success', true)
            ->count();

        $total = count($benchmark['results']);

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
