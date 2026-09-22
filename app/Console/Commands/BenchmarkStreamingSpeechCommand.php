<?php

namespace App\Console\Commands;

use App\Contracts\StreamingSpeechToTextProvider;
use App\Services\Benchmark\StreamingSpeechBenchmarkRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

class BenchmarkStreamingSpeechCommand extends Command
{
    protected $signature = 'benchmark:speech:stream
        {--provider= : Streaming speech benchmark provider profile}';

    protected $description =
        'Run canonical streaming speech benchmark fixtures';

    public function handle(
        StreamingSpeechBenchmarkRunner $runner,
    ): int {
        $profileName = (string) $this->option('provider');

        if ($profileName === '') {
            $this->error(
                'Streaming speech benchmark provider is required.'
            );

            return self::FAILURE;
        }

        $profile = config(
            "benchmarks.speech.streaming.providers.{$profileName}"
        );

        if (! is_array($profile)) {
            $this->error(
                "Unknown streaming speech benchmark provider: {$profileName}"
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
                "Invalid streaming speech benchmark profile: {$profileName}"
            );
        }

        config($configOverrides);

        app()->forgetInstance($contract);

        $provider = app($contract);

        if (! $provider instanceof StreamingSpeechToTextProvider) {
            throw new RuntimeException(
                "{$contract} must resolve to "
                .StreamingSpeechToTextProvider::class.'.'
            );
        }

        $datasetPath = config(
            'benchmarks.speech.dataset_path'
        );

        $resultsPath = config(
            'benchmarks.speech.results_path'
        );

        $chunkSizeBytes = config(
            'benchmarks.speech.streaming.chunk_size_bytes'
        );

        $chunkIntervalMs = config(
            'benchmarks.speech.streaming.chunk_interval_ms'
        );

        if (
            ! is_string($datasetPath)
            || ! is_string($resultsPath)
        ) {
            throw new RuntimeException(
                'Speech benchmark paths are not configured.'
            );
        }

        if (
            ! is_int($chunkSizeBytes)
            || $chunkSizeBytes <= 0
        ) {
            throw new RuntimeException(
                'Streaming speech benchmark chunk size is invalid.'
            );
        }

        if (
            ! is_int($chunkIntervalMs)
            || $chunkIntervalMs < 0
        ) {
            throw new RuntimeException(
                'Streaming speech benchmark chunk interval is invalid.'
            );
        }

        $this->info(
            "Running streaming speech benchmark profile: {$profileName}"
        );

        $this->line(
            "Provider: {$providerName}"
        );

        $this->line(
            "Model: {$modelName}"
        );

        $this->line(
            "Chunk size: {$chunkSizeBytes} bytes"
        );

        $this->line(
            "Chunk interval: {$chunkIntervalMs} ms"
        );

        $benchmark = $runner->run(
            provider: $provider,
            profileName: $profileName,
            providerName: $providerName,
            modelName: $modelName,
            datasetPath: $datasetPath,
            chunkSizeBytes: $chunkSizeBytes,
            chunkIntervalMs: $chunkIntervalMs,
        );

        $profileResultsPath =
            "{$resultsPath}/{$profileName}";

        File::ensureDirectoryExists(
            $profileResultsPath
        );

        $outputPath =
            "{$profileResultsPath}/streaming-results.json";

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
            $benchmark['results']
        )
            ->where('success', true)
            ->count();

        $total = count(
            $benchmark['results']
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
