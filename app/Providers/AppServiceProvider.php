<?php

namespace App\Providers;

use App\Contracts\SpeechToTextProvider;
use App\Services\Speech\DeepgramSpeechToTextProvider;
use App\Services\Speech\GoogleSpeechToTextProvider;
use Google\Cloud\Speech\V2\Client\SpeechClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            SpeechClient::class,
            function (): SpeechClient {
                $credentialsPath = config(
                    'services.google.speech.credentials_path'
                );

                if (is_string($credentialsPath) && $credentialsPath !== '') {
                    if (! is_readable($credentialsPath)) {
                        throw new RuntimeException(
                            'Google credentials file is not readable.'
                        );
                    }

                    putenv(
                        "GOOGLE_APPLICATION_CREDENTIALS={$credentialsPath}"
                    );
                }

                $clientOptions = [];

                $apiEndpoint = config(
                    'services.google.speech.api_endpoint'
                );

                if (is_string($apiEndpoint) && $apiEndpoint !== '') {
                    $clientOptions['apiEndpoint'] = $apiEndpoint;
                }

                return new SpeechClient($clientOptions);
            },
        );

        $this->app->bind(
            SpeechToTextProvider::class,
            function ($app): GoogleSpeechToTextProvider {
                $projectId = config(
                    'services.google.speech.project_id',
                );

                if (! is_string($projectId) || $projectId === '') {
                    throw new RuntimeException(
                        'Google Cloud project ID is not configured.',
                    );
                }

                return new GoogleSpeechToTextProvider(
                    client: $app->make(SpeechClient::class),
                    projectId: $projectId,
                    location: (string) config(
                        'services.google.speech.location',
                        'global',
                    ),
                    model: (string) config(
                        'services.google.speech.model',
                        'short',
                    ),
                );
            },
        );

        $this->app->bind(
            DeepgramSpeechToTextProvider::class,
            function ($app): DeepgramSpeechToTextProvider {
                $apiKey = config(
                    'services.deepgram.api_key',
                );

                $endpoint = config(
                    'services.deepgram.endpoint',
                );

                $model = config(
                    'services.deepgram.model',
                    'nova-3',
                );

                if (! is_string($apiKey) || $apiKey === '') {
                    throw new RuntimeException(
                        'Deepgram API key is not configured.',
                    );
                }

                if (! is_string($endpoint) || $endpoint === '') {
                    throw new RuntimeException(
                        'Deepgram API endpoint is not configured.',
                    );
                }

                if (! is_string($model) || $model === '') {
                    throw new RuntimeException(
                        'Deepgram model is not configured.',
                    );
                }

                return new DeepgramSpeechToTextProvider(
                    http: $app->make(Factory::class),
                    apiKey: $apiKey,
                    endpoint: $endpoint,
                    model: $model,
                );
            },
        );
    }

    public function boot(): void
    {
        //
    }
}
