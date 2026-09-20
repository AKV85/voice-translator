<?php

namespace App\Services\Speech;

use App\Contracts\SpeechToTextProvider;
use App\DTO\AudioInput;
use App\DTO\TranscriptionResult;
use App\Enums\Language;
use App\Exceptions\SpeechRecognitionException;
use Google\Cloud\Speech\V2\AutoDetectDecodingConfig;
use Google\Cloud\Speech\V2\Client\SpeechClient;
use Google\Cloud\Speech\V2\RecognitionConfig;
use Google\Cloud\Speech\V2\RecognizeRequest;
use Throwable;

final readonly class GoogleSpeechToTextProvider implements SpeechToTextProvider
{
    public function __construct(
        private SpeechClient $client,
        private string $projectId,
        private string $location = 'global',
        private string $model = 'short',
    ) {}

    public function transcribe(
        AudioInput $audio,
        Language $sourceLanguage,
    ): TranscriptionResult {
        $config = (new RecognitionConfig)
            ->setAutoDecodingConfig(
                new AutoDetectDecodingConfig,
            )
            ->setLanguageCodes([
                $this->languageCode($sourceLanguage),
            ])
            ->setModel($this->model);

        $request = (new RecognizeRequest)
            ->setRecognizer(
                SpeechClient::recognizerName(
                    $this->projectId,
                    $this->location,
                    '_',
                ),
            )
            ->setConfig($config)
            ->setContent($audio->content);

        try {
            $response = $this->client->recognize($request);
        } catch (Throwable $exception) {
            throw new SpeechRecognitionException(
                'Speech recognition provider failed.',
                previous: $exception,
            );
        }

        $transcripts = [];

        foreach ($response->getResults() as $result) {
            $alternatives = $result->getAlternatives();

            if (count($alternatives) === 0) {
                continue;
            }

            $transcript = trim(
                $alternatives[0]->getTranscript(),
            );

            if ($transcript !== '') {
                $transcripts[] = $transcript;
            }
        }

        $text = trim(implode(' ', $transcripts));

        if ($text === '') {
            throw new SpeechRecognitionException(
                'No speech could be recognized.',
            );
        }

        return new TranscriptionResult(
            text: $text,
        );
    }

    private function languageCode(Language $language): string
    {
        return match ($language) {
            Language::English => 'en-US',
            Language::Russian => 'ru-RU',
        };
    }
}
