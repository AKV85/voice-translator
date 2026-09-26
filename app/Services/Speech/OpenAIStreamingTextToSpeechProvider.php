<?php

namespace App\Services\Speech;

use App\Contracts\StreamingTextToSpeechProvider;
use App\DTO\SpeechSynthesisResult;
use App\Enums\Language;
use App\Exceptions\SpeechSynthesisException;
use Closure;
use Illuminate\Http\Client\Factory;
use Throwable;

final readonly class OpenAIStreamingTextToSpeechProvider implements StreamingTextToSpeechProvider
{
    /**
     * 10 ms of PCM16 mono audio at 24 kHz:
     *
     * 24,000 samples/s
     * * 0.010 s
     * * 2 bytes/sample
     * = 480 bytes
     */
    private const STREAM_READ_BYTES = 480;

    public function __construct(
        private Factory $http,
        private string $apiKey,
        private string $model,
        private string $endpoint,
        private string $voice,
    ) {}

    public function synthesize(
        string $text,
        Language $targetLanguage,
        Closure $onAudioChunk,
    ): SpeechSynthesisResult {
        if (trim($text) === '') {
            throw new SpeechSynthesisException(
                'Speech synthesis text must not be empty.',
            );
        }

        $audio = '';

        try {
            $response = $this->http
                ->withToken($this->apiKey)
                ->accept('application/octet-stream')
                ->asJson()
                ->connectTimeout(5)
                ->timeout(60)
                ->withOptions([
                    'stream' => true,
                ])
                ->post(
                    $this->endpoint,
                    [
                        'model' => $this->model,

                        'input' => $text,

                        'voice' => $this->voice,

                        'instructions' => $this->instructions(
                            $targetLanguage,
                        ),

                        'response_format' => 'pcm',

                        'stream_format' => 'audio',
                    ],
                );

            $response->throw();

            $stream = $response
                ->toPsrResponse()
                ->getBody();

            try {
                while (! $stream->eof()) {
                    $chunk = $stream->read(
                        self::STREAM_READ_BYTES,
                    );

                    if ($chunk === '') {
                        break;
                    }

                    $onAudioChunk($chunk);

                    $audio .= $chunk;
                }
            } finally {
                $stream->close();
            }
        } catch (Throwable $exception) {
            throw new SpeechSynthesisException(
                'Text-to-speech provider failed.',
                previous: $exception,
            );
        }

        if ($audio === '') {
            throw new SpeechSynthesisException(
                'Text-to-speech provider returned empty audio.',
            );
        }

        return new SpeechSynthesisResult(
            audio: $audio,
            mimeType: 'audio/pcm',
        );
    }

    private function instructions(
        Language $targetLanguage,
    ): string {
        $language = match ($targetLanguage) {
            Language::English => 'English',
            Language::Russian => 'Russian',
        };

        return sprintf(
            'Speak clearly and naturally in %s. '
            .'Read the input exactly as written. '
            .'Do not add, omit, paraphrase, or translate content. '
            .'Preserve names, identifiers, numbers, and logistics terms.',
            $language,
        );
    }
}
