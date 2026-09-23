<?php

namespace App\Providers;

use App\Contracts\AudioDurationProbe;
use App\Contracts\StreamingWebSocketFactory;
use App\Services\Benchmark\FfprobeAudioDurationProbe;
use App\Services\Speech\DeepgramFluxSpeechToTextProvider;
use App\Services\WebSocket\PhrityStreamingWebSocketFactory;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class DeepgramFluxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            AudioDurationProbe::class,
            FfprobeAudioDurationProbe::class,
        );

        $this->app->bind(
            StreamingWebSocketFactory::class,
            PhrityStreamingWebSocketFactory::class,
        );

        $this->app->bind(
            DeepgramFluxSpeechToTextProvider::class,
            function ($app): DeepgramFluxSpeechToTextProvider {
                $apiKey = config(
                    'services.deepgram.api_key',
                );

                $endpoint = config(
                    'services.deepgram.flux_endpoint',
                    'wss://api.deepgram.com/v2/listen',
                );

                $model = config(
                    'services.deepgram.flux_model',
                    'flux-general-multi',
                );

                $keyterms = config(
                    'services.deepgram.flux_keyterms',
                    [],
                );

                if (
                    ! is_string($apiKey)
                    || $apiKey === ''
                ) {
                    throw new RuntimeException(
                        'Deepgram API key is not configured.'
                    );
                }

                if (
                    ! is_string($endpoint)
                    || $endpoint === ''
                ) {
                    throw new RuntimeException(
                        'Deepgram Flux endpoint is not configured.'
                    );
                }

                if (
                    ! is_string($model)
                    || $model === ''
                ) {
                    throw new RuntimeException(
                        'Deepgram Flux model is not configured.'
                    );
                }

                if (! is_array($keyterms)) {
                    throw new RuntimeException(
                        'Deepgram Flux keyterms must be an array.'
                    );
                }

                if (count($keyterms) > 100) {
                    throw new RuntimeException(
                        'Deepgram Flux supports at most 100 keyterms.'
                    );
                }

                foreach ($keyterms as $keyterm) {
                    if (
                        ! is_string($keyterm)
                        || trim($keyterm) === ''
                    ) {
                        throw new RuntimeException(
                            'Deepgram Flux keyterms must be non-empty strings.'
                        );
                    }
                }

                /** @var list<string> $keyterms */
                $keyterms = array_values(
                    $keyterms,
                );

                return new DeepgramFluxSpeechToTextProvider(
                    webSocketFactory: $app->make(
                        StreamingWebSocketFactory::class,
                    ),
                    apiKey: $apiKey,
                    endpoint: $endpoint,
                    model: $model,
                    keyterms: $keyterms,
                );
            },
        );
    }
}
