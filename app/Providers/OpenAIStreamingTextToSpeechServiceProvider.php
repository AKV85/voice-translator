<?php

namespace App\Providers;

use App\Services\Speech\OpenAIStreamingTextToSpeechProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class OpenAIStreamingTextToSpeechServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            OpenAIStreamingTextToSpeechProvider::class,
            function ($app): OpenAIStreamingTextToSpeechProvider {
                $apiKey = config(
                    'services.openai.api_key',
                );

                $model = config(
                    'services.openai.text_to_speech.model',
                );

                $endpoint = config(
                    'services.openai.text_to_speech.endpoint',
                );

                $voice = config(
                    'services.openai.text_to_speech.voice',
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
                        'OpenAI text-to-speech model is not configured.',
                    );
                }

                if (
                    ! is_string($endpoint)
                    || $endpoint === ''
                ) {
                    throw new RuntimeException(
                        'OpenAI text-to-speech endpoint is not configured.',
                    );
                }

                if (
                    ! is_string($voice)
                    || $voice === ''
                ) {
                    throw new RuntimeException(
                        'OpenAI text-to-speech voice is not configured.',
                    );
                }

                return new OpenAIStreamingTextToSpeechProvider(
                    http: $app->make(Factory::class),
                    apiKey: $apiKey,
                    model: $model,
                    endpoint: $endpoint,
                    voice: $voice,
                );
            },
        );
    }
}
