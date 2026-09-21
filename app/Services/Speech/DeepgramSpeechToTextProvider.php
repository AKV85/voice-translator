<?php

namespace App\Services\Speech;

use App\Contracts\SpeechToTextProvider;
use App\DTO\AudioInput;
use App\DTO\TranscriptionResult;
use App\Enums\Language;
use App\Exceptions\SpeechRecognitionException;
use Illuminate\Http\Client\Factory;
use Throwable;

final readonly class DeepgramSpeechToTextProvider implements SpeechToTextProvider
{
    public function __construct(
        private Factory $http,
        private string $apiKey,
        private string $endpoint,
        private string $model = 'nova-3',
    ) {}

    public function transcribe(
        AudioInput $audio,
        Language $sourceLanguage,
    ): TranscriptionResult {
        $url = $this->endpoint.'?'.http_build_query([
            'model' => $this->model,
            'language' => $this->languageCode($sourceLanguage),
        ]);

        try {
            $response = $this->http
                ->withHeaders([
                    'Authorization' => "Token {$this->apiKey}",
                    'Accept' => 'application/json',
                    'Content-Type' => $audio->mimeType,
                ])
                ->withBody(
                    $audio->content,
                    $audio->mimeType,
                )
                ->timeout(30)
                ->post($url)
                ->throw();
        } catch (Throwable $exception) {
            throw new SpeechRecognitionException(
                'Speech recognition provider failed.',
                previous: $exception,
            );
        }

        $transcript = $response->json(
            'results.channels.0.alternatives.0.transcript',
        );

        if (! is_string($transcript) || trim($transcript) === '') {
            throw new SpeechRecognitionException(
                'No speech could be recognized.',
            );
        }

        return new TranscriptionResult(
            text: trim($transcript),
        );
    }

    private function languageCode(Language $language): string
    {
        return match ($language) {
            Language::English => 'en',
            Language::Russian => 'ru',
        };
    }
}
