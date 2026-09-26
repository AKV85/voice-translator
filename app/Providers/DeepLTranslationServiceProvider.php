<?php

namespace App\Providers;

use App\Services\Translation\DeepLTranslationProvider;
use DeepL\DeepLClient;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class DeepLTranslationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            DeepLClient::class,
            function (): DeepLClient {
                $authKey = config(
                    'services.deepl.auth_key',
                );

                if (
                    ! is_string($authKey)
                    || $authKey === ''
                ) {
                    throw new RuntimeException(
                        'DeepL API key is not configured.',
                    );
                }

                return new DeepLClient(
                    $authKey,
                    [
                        'timeout' => 10.0,
                        'max_retries' => 2,
                    ],
                );
            },
        );

        $this->app->bind(
            DeepLTranslationProvider::class,
            function ($app): DeepLTranslationProvider {
                $modelType = config(
                    'services.deepl.model_type',
                    'latency_optimized',
                );

                if (
                    ! is_string($modelType)
                    || $modelType === ''
                ) {
                    throw new RuntimeException(
                        'DeepL model type is not configured.',
                    );
                }

                return new DeepLTranslationProvider(
                    client: $app->make(
                        DeepLClient::class,
                    ),
                    modelType: $modelType,
                );
            },
        );
    }
}
