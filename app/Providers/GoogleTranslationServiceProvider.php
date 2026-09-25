<?php

namespace App\Providers;

use App\Contracts\TranslationProvider;
use App\Services\Translation\GoogleTranslationProvider;
use Google\Cloud\Translate\V3\Client\TranslationServiceClient;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class GoogleTranslationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            TranslationServiceClient::class,
            function (): TranslationServiceClient {
                $credentialsPath = config(
                    'services.google.translation.credentials_path',
                );

                if (
                    is_string($credentialsPath)
                    && $credentialsPath !== ''
                ) {
                    if (! is_readable($credentialsPath)) {
                        throw new RuntimeException(
                            'Google credentials file is not readable.',
                        );
                    }

                    putenv(
                        "GOOGLE_APPLICATION_CREDENTIALS={$credentialsPath}"
                    );
                }

                return new TranslationServiceClient;
            },
        );

        $this->app->bind(
            TranslationProvider::class,
            function ($app): GoogleTranslationProvider {
                $projectId = config(
                    'services.google.translation.project_id',
                );

                $location = config(
                    'services.google.translation.location',
                    'global',
                );

                $model = config(
                    'services.google.translation.model',
                    'general/nmt',
                );

                if (
                    ! is_string($projectId)
                    || $projectId === ''
                ) {
                    throw new RuntimeException(
                        'Google Cloud project ID is not configured.',
                    );
                }

                if (
                    ! is_string($location)
                    || $location === ''
                ) {
                    throw new RuntimeException(
                        'Google Translation location is not configured.',
                    );
                }

                if (
                    ! is_string($model)
                    || $model === ''
                ) {
                    throw new RuntimeException(
                        'Google Translation model is not configured.',
                    );
                }

                return new GoogleTranslationProvider(
                    client: $app->make(
                        TranslationServiceClient::class,
                    ),
                    projectId: $projectId,
                    location: $location,
                    model: $model,
                );
            },
        );
    }
}
