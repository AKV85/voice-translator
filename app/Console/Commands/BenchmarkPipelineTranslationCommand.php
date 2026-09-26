<?php

namespace App\Console\Commands;

use App\Contracts\StreamingSpeechToTextProvider;
use App\Contracts\StreamingTextToSpeechProvider;
use App\Contracts\TranslationProvider;
use App\Services\Benchmark\PipelineTranslationBenchmarkRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

class BenchmarkPipelineTranslationCommand extends Command
{
    protected $signature =
        'benchmark:translation:pipeline
        {--provider= : Pipeline translation benchmark profile}
        {--pair= : Language pair, for example ru-en or en-ru}
        {--phrase= : Run only one speech benchmark phrase by ID}
        {--runs= : Number of runs per fixture}';

    protected $description =
        'Run canonical STT to translation to TTS benchmark fixtures';

    public function handle(
        PipelineTranslationBenchmarkRunner $runner,
    ): int {
        $profileName =
            (string) $this->option(
                'provider',
            );

        if ($profileName === '') {
            $this->error(
                'Pipeline translation benchmark provider is required.',
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
            "benchmarks.translation.pipeline.providers.{$profileName}",
        );

        if (! is_array($profile)) {
            $this->error(
                "Unknown pipeline translation benchmark provider: {$profileName}",
            );

            return self::FAILURE;
        }

        $speechProfile =
            $this->stageProfile(
                profile: $profile,
                key: 'speech_to_text',
                stageName: 'speech-to-text',
            );

        $translationProfile =
            $this->stageProfile(
                profile: $profile,
                key: 'translation',
                stageName: 'translation',
            );

        $textToSpeechProfile =
            $this->stageProfile(
                profile: $profile,
                key: 'text_to_speech',
                stageName: 'text-to-speech',
            );

        config([
            ...$speechProfile['config'],
            ...$translationProfile['config'],
            ...$textToSpeechProfile['config'],
        ]);

        app()->forgetInstance(
            $speechProfile['contract'],
        );

        app()->forgetInstance(
            $translationProfile['contract'],
        );

        app()->forgetInstance(
            $textToSpeechProfile['contract'],
        );

        $speechToTextProvider =
            app(
                $speechProfile['contract'],
            );

        if (
            ! $speechToTextProvider
                instanceof StreamingSpeechToTextProvider
        ) {
            throw new RuntimeException(
                $speechProfile['contract']
                .' must resolve to '
                .StreamingSpeechToTextProvider::class
                .'.',
            );
        }

        $translationProvider =
            app(
                $translationProfile['contract'],
            );

        if (
            ! $translationProvider
                instanceof TranslationProvider
        ) {
            throw new RuntimeException(
                $translationProfile['contract']
                .' must resolve to '
                .TranslationProvider::class
                .'.',
            );
        }

        $textToSpeechProvider =
            app(
                $textToSpeechProfile['contract'],
            );

        if (
            ! $textToSpeechProvider
                instanceof StreamingTextToSpeechProvider
        ) {
            throw new RuntimeException(
                $textToSpeechProfile['contract']
                .' must resolve to '
                .StreamingTextToSpeechProvider::class
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
            'benchmarks.translation.pipeline.chunk_duration_ms',
            80,
        );

        if (
            ! is_string($speechDatasetPath)
            || ! is_string($translationDatasetPath)
            || ! is_string($resultsPath)
        ) {
            throw new RuntimeException(
                'Pipeline translation benchmark paths are not configured.',
            );
        }

        if (
            ! is_int($chunkDurationMs)
            || $chunkDurationMs <= 0
        ) {
            throw new RuntimeException(
                'Pipeline translation benchmark chunk duration is invalid.',
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
                'benchmarks.translation.pipeline.runs_per_fixture',
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
            "Running pipeline translation benchmark profile: {$profileName}",
        );

        $this->line(
            'STT: '
            .$speechProfile['provider']
            .' / '
            .$speechProfile['model'],
        );

        $this->line(
            'Translation: '
            .$translationProfile['provider']
            .' / '
            .$translationProfile['model'],
        );

        $this->line(
            'TTS: '
            .$textToSpeechProfile['provider']
            .' / '
            .$textToSpeechProfile['model'],
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
                speechToTextProvider: $speechToTextProvider,

                translationProvider: $translationProvider,

                textToSpeechProvider: $textToSpeechProvider,

                profileName: $profileName,

                speechProviderName: $speechProfile['provider'],

                speechModelName: $speechProfile['model'],

                translationProviderName: $translationProfile['provider'],

                translationModelName: $translationProfile['model'],

                textToSpeechProviderName: $textToSpeechProfile['provider'],

                textToSpeechModelName: $textToSpeechProfile['model'],

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
     * @param  array<string, mixed>  $profile
     * @return array{
     *     contract: string,
     *     provider: string,
     *     model: string,
     *     config: array<string, mixed>
     * }
     */
    private function stageProfile(
        array $profile,
        string $key,
        string $stageName,
    ): array {
        $stage =
            $profile[$key]
            ?? null;

        if (! is_array($stage)) {
            throw new RuntimeException(
                "Pipeline {$stageName} profile is not configured.",
            );
        }

        $contract =
            $stage['contract']
            ?? null;

        $provider =
            $stage['provider']
            ?? null;

        $model =
            $stage['model']
            ?? null;

        $config =
            $stage['config']
            ?? [];

        if (
            ! is_string($contract)
            || $contract === ''
            || ! is_string($provider)
            || $provider === ''
            || ! is_string($model)
            || $model === ''
            || ! is_array($config)
        ) {
            throw new RuntimeException(
                "Invalid pipeline {$stageName} benchmark profile.",
            );
        }

        /** @var array<string, mixed> $config */
        return [
            'contract' => $contract,

            'provider' => $provider,

            'model' => $model,

            'config' => $config,
        ];
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

        $stage =
            $result[
                'failed_stage'
            ]
            ?? null;

        $errorName =
            is_string($error)
                ? $this->shortClassName(
                    $error,
                )
                : 'unknown error';

        $prefix =
            is_string($stage)
            && $stage !== ''
                ? "[{$stage}] "
                : '';

        if (
            ! is_string($message)
            || trim($message) === ''
        ) {
            return $prefix.$errorName;
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

        return $prefix
            .$errorName
            .': '
            .$message;
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
            return 'pipeline-results-'
                .$this->safeFilenamePart(
                    $phraseId,
                )
                .'.json';
        }

        if ($languagePair !== null) {
            return 'pipeline-results-'
                .$this->safeFilenamePart(
                    $languagePair,
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
}
