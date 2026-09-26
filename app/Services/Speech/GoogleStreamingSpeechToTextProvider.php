<?php

namespace App\Services\Speech;

use App\Contracts\StreamingSpeechToTextProvider;
use App\DTO\StreamingTranscriptionResult;
use App\Enums\Language;
use App\Exceptions\SpeechRecognitionException;
use Closure;
use Google\Cloud\Speech\V2\AutoDetectDecodingConfig;
use Google\Cloud\Speech\V2\Client\SpeechClient;
use Google\Cloud\Speech\V2\RecognitionConfig;
use Google\Cloud\Speech\V2\StreamingRecognitionConfig;
use Google\Cloud\Speech\V2\StreamingRecognitionFeatures;
use Google\Cloud\Speech\V2\StreamingRecognitionFeatures\EndpointingSensitivity;
use Google\Cloud\Speech\V2\StreamingRecognizeRequest;
use Google\Cloud\Speech\V2\StreamingRecognizeResponse;
use InvalidArgumentException;
use Throwable;

final readonly class GoogleStreamingSpeechToTextProvider implements StreamingSpeechToTextProvider
{
    private const MAX_AUDIO_BYTES_PER_REQUEST = 15 * 1024;

    public function __construct(
        private SpeechClient $client,
        private string $projectId,
        private string $location = 'global',
        private string $model = 'chirp_3',
        private string $endpointingSensitivity = 'standard',
    ) {}

    public function transcribe(
        iterable $audioChunks,
        string $mimeType,
        Language $sourceLanguage,
        Closure $onTranscript,
    ): StreamingTranscriptionResult {
        $config = (new RecognitionConfig)
            ->setAutoDecodingConfig(
                new AutoDetectDecodingConfig,
            )
            ->setLanguageCodes([
                $this->languageCode($sourceLanguage),
            ])
            ->setModel($this->model);

        $streamingFeatures =
            (new StreamingRecognitionFeatures)
                ->setEndpointingSensitivity(
                    $this->endpointingSensitivity(),
                );

        $streamingConfig =
            (new StreamingRecognitionConfig)
                ->setConfig($config)
                ->setStreamingFeatures(
                    $streamingFeatures,
                );

        try {
            $stream = $this->client->streamingRecognize();

            $stream->write(
                (new StreamingRecognizeRequest)
                    ->setRecognizer(
                        SpeechClient::recognizerName(
                            $this->projectId,
                            $this->location,
                            '_',
                        ),
                    )
                    ->setStreamingConfig(
                        $streamingConfig,
                    ),
            );

            $audioWritten = false;

            foreach ($audioChunks as $chunk) {
                if ($chunk === '') {
                    continue;
                }

                foreach (
                    str_split(
                        $chunk,
                        self::MAX_AUDIO_BYTES_PER_REQUEST,
                    ) as $audioChunk
                ) {
                    $stream->write(
                        (new StreamingRecognizeRequest)
                            ->setAudio($audioChunk),
                    );

                    $audioWritten = true;
                }
            }

            if (! $audioWritten) {
                throw new SpeechRecognitionException(
                    'No audio was provided for speech recognition.',
                );
            }

            $finalSegments = [];

            foreach ($stream->closeWriteAndReadAll() as $response) {
                if (! $response instanceof StreamingRecognizeResponse) {
                    continue;
                }

                foreach ($response->getResults() as $result) {
                    if (! $result->getIsFinal()) {
                        continue;
                    }

                    $alternatives =
                        $result->getAlternatives();

                    if (count($alternatives) === 0) {
                        continue;
                    }

                    $transcript = trim(
                        $alternatives[0]->getTranscript(),
                    );

                    if ($transcript !== '') {
                        $finalSegments[] = $transcript;
                    }
                }
            }

            $text = trim(
                implode(
                    ' ',
                    $finalSegments,
                ),
            );

            if ($text === '') {
                throw new SpeechRecognitionException(
                    'No speech could be recognized.',
                );
            }

            $onTranscript(
                $text,
                true,
            );

            return new StreamingTranscriptionResult(
                text: $text,
            );
        } catch (SpeechRecognitionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new SpeechRecognitionException(
                'Streaming speech recognition provider failed.',
                previous: $exception,
            );
        }
    }

    private function endpointingSensitivity(): int
    {
        return match ($this->endpointingSensitivity) {
            'standard' => EndpointingSensitivity::ENDPOINTING_SENSITIVITY_STANDARD,
            'short' => EndpointingSensitivity::ENDPOINTING_SENSITIVITY_SHORT,
            default => throw new InvalidArgumentException(
                sprintf(
                    'Unsupported Google endpointing sensitivity [%s].',
                    $this->endpointingSensitivity,
                ),
            ),
        };
    }

    private function languageCode(
        Language $language,
    ): string {
        return match ($language) {
            Language::English => 'en-US',
            Language::Russian => 'ru-RU',
        };
    }
}
