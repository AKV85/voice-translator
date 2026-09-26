<?php

namespace App\Providers;

use App\Services\Translation\OpenAITranslationProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class OpenAITranslationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            OpenAITranslationProvider::class,
            function ($app): OpenAITranslationProvider {
                $apiKey = config(
                    'services.openai.api_key',
                );

                $model = config(
                    'services.openai.translation.model',
                );

                $endpoint = config(
                    'services.openai.translation.endpoint',
                );

                $serviceTier = config(
                    'services.openai.translation.service_tier',
                );

                if (
                    ! is_string($apiKey)
                    || $apiKey === ''
                ) {
                    throw new RuntimeException(
                        'OpenAI API key is not configured.',
                    );
                }

                if (
                    ! is_string($model)
                    || $model === ''
                ) {
                    throw new RuntimeException(
                        'OpenAI translation model is not configured.',
                    );
                }

                if (
                    ! is_string($endpoint)
                    || $endpoint === ''
                ) {
                    throw new RuntimeException(
                        'OpenAI translation endpoint is not configured.',
                    );
                }

                if (
                    ! is_string($serviceTier)
                    || $serviceTier === ''
                ) {
                    throw new RuntimeException(
                        'OpenAI service tier is not configured.',
                    );
                }

                return new OpenAITranslationProvider(
                    http: $app->make(Factory::class),
                    apiKey: $apiKey,
                    model: $model,
                    endpoint: $endpoint,
                    serviceTier: $serviceTier,
                );
            },
        );
    }
}
