<?php

namespace App\Providers;

use App\Contracts\Pcm16AudioConverter;
use App\Contracts\StreamingWebSocketFactory;
use App\Services\Benchmark\FfmpegPcm16AudioConverter;
use App\Services\Translation\OpenAIRealtimeTranslationProvider;
use App\Services\WebSocket\PhrityStreamingWebSocketFactory;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class OpenAIRealtimeTranslationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            Pcm16AudioConverter::class,
            FfmpegPcm16AudioConverter::class,
        );

        $this->app->bind(
            StreamingWebSocketFactory::class,
            PhrityStreamingWebSocketFactory::class,
        );

        $this->app->bind(
            OpenAIRealtimeTranslationProvider::class,
            function ($app): OpenAIRealtimeTranslationProvider {
                $apiKey = config(
                    'services.openai.api_key',
                );

                $endpoint = config(
                    'services.openai.realtime_translation.endpoint',
                    'wss://api.openai.com/v1/realtime/translations',
                );

                $model = config(
                    'services.openai.realtime_translation.model',
                    'gpt-realtime-translate',
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
                    ! is_string($endpoint)
                    || $endpoint === ''
                ) {
                    throw new RuntimeException(
                        'OpenAI realtime translation endpoint is not configured.',
                    );
                }

                if (
                    ! is_string($model)
                    || $model === ''
                ) {
                    throw new RuntimeException(
                        'OpenAI realtime translation model is not configured.',
                    );
                }

                return new OpenAIRealtimeTranslationProvider(
                    webSocketFactory: $app->make(
                        StreamingWebSocketFactory::class,
                    ),
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
