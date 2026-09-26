<?php

use App\Contracts\TranslationProvider;
use App\DTO\TranslationResult;
use App\Enums\Language;
use Illuminate\Support\Facades\File;

final class TranslationBenchmarkFakeProvider implements TranslationProvider
{
    /**
     * @var array<int, array{
     *     text: string,
     *     source_language: Language,
     *     target_language: Language
     * }>
     */
    public array $requests = [];

    public function translate(
        string $text,
        Language $sourceLanguage,
        Language $targetLanguage,
    ): TranslationResult {
        $this->requests[] = [
            'text' => $text,
            'source_language' => $sourceLanguage,
            'target_language' => $targetLanguage,
        ];

        return new TranslationResult(
            text: match ($sourceLanguage) {
                Language::Russian => 'I need a truck.',
                Language::English => 'Мне нужен грузовик.',
            },
        );
    }
}

beforeEach(function () {
    $basePath = storage_path(
        'framework/testing/translation-benchmark',
    );

    File::deleteDirectory(
        $basePath,
    );

    File::ensureDirectoryExists(
        $basePath,
    );

    $datasetPath =
        "{$basePath}/phrases.json";

    $resultsPath =
        "{$basePath}/results";

    File::put(
        $datasetPath,
        json_encode(
            [
                'version' => 1,
                'language_pairs' => [
                    'ru-en',
                    'en-ru',
                ],
                'phrases' => [
                    [
                        'id' => 'ru-en-001',
                        'source_language' => 'ru',
                        'target_language' => 'en',
                        'category' => 'logistics',
                        'source' => 'Мне нужен грузовик.',
                        'reference' => 'I need a truck.',
                        'critical_elements' => [
                            [
                                'name' => 'truck',
                                'accepted' => [
                                    'truck',
                                ],
                            ],
                        ],
                    ],
                    [
                        'id' => 'en-ru-001',
                        'source_language' => 'en',
                        'target_language' => 'ru',
                        'category' => 'logistics',
                        'source' => 'I need a truck.',
                        'reference' => 'Мне нужен грузовик.',
                        'critical_elements' => [
                            [
                                'name' => 'truck',
                                'accepted' => [
                                    'грузовик',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR,
        ).PHP_EOL,
    );

    config([
        'benchmarks.translation.dataset_path' => $datasetPath,

        'benchmarks.translation.results_path' => $resultsPath,

        'benchmarks.translation.providers.fake' => [
            'contract' => TranslationProvider::class,

            'provider' => 'fake',

            'model' => 'fake-model',

            'config' => [],
        ],
    ]);
});

afterEach(function () {
    File::deleteDirectory(
        storage_path(
            'framework/testing/translation-benchmark',
        ),
    );
});

it(
    'runs translation benchmark for all language pairs',
    function () {
        $provider =
            new TranslationBenchmarkFakeProvider;

        $this->app->instance(
            TranslationProvider::class,
            $provider,
        );

        $this->artisan(
            'benchmark:translation',
            [
                '--provider' => 'fake',
            ],
        )
            ->expectsOutput(
                'Running translation benchmark profile: fake'
            )
            ->expectsOutput(
                'Provider: fake'
            )
            ->expectsOutput(
                'Model: fake-model'
            )
            ->expectsOutput(
                'Completed: 2/2 successful.'
            )
            ->assertExitCode(0);

        expect(
            $provider->requests
        )->toHaveCount(2);

        $resultsPath = config(
            'benchmarks.translation.results_path',
        );

        expect($resultsPath)
            ->toBeString();

        $filePath =
            "{$resultsPath}/fake/results.json";

        expect(
            File::exists($filePath)
        )->toBeTrue();

        $benchmark = json_decode(
            File::get($filePath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect(
            $benchmark['fixture_count']
        )->toBe(2);

        expect(
            $benchmark['results'][0]['actual']
        )->toBe('I need a truck.');

        expect(
            $benchmark['results'][0]['success']
        )->toBeTrue();

        expect(
            $benchmark['results'][0]['latency_ms']
        )->toBeGreaterThanOrEqual(0);

        expect(
            $benchmark['results'][0]['critical_elements'][0]['name']
        )->toBe('truck');
    },
);

it(
    'can run only one language pair',
    function () {
        $provider =
            new TranslationBenchmarkFakeProvider;

        $this->app->instance(
            TranslationProvider::class,
            $provider,
        );

        $this->artisan(
            'benchmark:translation',
            [
                '--provider' => 'fake',
                '--pair' => 'ru-en',
            ],
        )
            ->expectsOutput(
                'Language pair: ru-en'
            )
            ->expectsOutput(
                'Completed: 1/1 successful.'
            )
            ->assertExitCode(0);

        expect(
            $provider->requests
        )->toHaveCount(1);

        expect(
            $provider->requests[0]['source_language']
        )->toBe(
            Language::Russian,
        );

        expect(
            $provider->requests[0]['target_language']
        )->toBe(
            Language::English,
        );

        $resultsPath = config(
            'benchmarks.translation.results_path',
        );

        expect($resultsPath)
            ->toBeString();

        expect(
            File::exists(
                "{$resultsPath}/fake/results-ru-en.json"
            )
        )->toBeTrue();
    },
);

it(
    'fails for an unknown provider profile',
    function () {
        $this->artisan(
            'benchmark:translation',
            [
                '--provider' => 'missing-provider',
            ],
        )
            ->expectsOutput(
                'Unknown translation benchmark provider: missing-provider'
            )
            ->assertExitCode(1);
    },
);
