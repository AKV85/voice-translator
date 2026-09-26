<?php

namespace App\Services\Translation;

use App\Contracts\TranslationProvider;
use App\DTO\TranslationResult;
use App\Enums\Language;
use App\Exceptions\TranslationException;
use Illuminate\Http\Client\Factory;
use Throwable;

final readonly class OpenAITranslationProvider implements TranslationProvider
{
    public function __construct(
        private Factory $http,
        private string $apiKey,
        private string $model,
        private string $endpoint,
        private string $serviceTier,
    ) {}

    public function translate(
        string $text,
        Language $sourceLanguage,
        Language $targetLanguage,
    ): TranslationResult {
        if (trim($text) === '') {
            throw new TranslationException(
                'Translation text must not be empty.',
            );
        }

        try {
            $response = $this->http
                ->withToken($this->apiKey)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->post(
                    $this->endpoint,
                    [
                        'model' => $this->model,

                        'instructions' => $this->instructions(
                            $sourceLanguage,
                            $targetLanguage,
                        ),

                        'input' => $text,

                        'reasoning' => [
                            'effort' => 'none',
                        ],

                        'text' => [
                            'verbosity' => 'low',
                        ],

                        'max_output_tokens' => 256,

                        'service_tier' => $this->serviceTier,

                        'store' => false,
                    ],
                );

            $response->throw();

            $translatedText = $this->extractOutputText(
                $response->json(),
            );
        } catch (Throwable $exception) {
            throw new TranslationException(
                'Translation provider failed.',
                previous: $exception,
            );
        }

        if ($translatedText === '') {
            throw new TranslationException(
                'Translation provider returned an empty translation.',
            );
        }

        return new TranslationResult(
            text: $translatedText,
        );
    }

    private function instructions(
        Language $sourceLanguage,
        Language $targetLanguage,
    ): string {
        return sprintf(
            <<<'PROMPT'
Translate the input text from %s to %s.

Return only the translated text.
Do not add explanations, comments, quotation marks, or formatting.

Preserve:
- proper names as names; transliterate them when appropriate instead of translating their lexical meaning;
- identifiers, registration numbers, codes, and numbers;
- negation;
- logistics actions and instructions;
- times, distances, temperatures, and locations.

Do not omit information.
Do not add information that is not present in the source.
Use natural target-language grammar while preserving the original meaning.
PROMPT,
            $this->languageName($sourceLanguage),
            $this->languageName($targetLanguage),
        );
    }

    private function extractOutputText(mixed $payload): string
    {
        if (! is_array($payload)) {
            return '';
        }

        $output = $payload['output'] ?? null;

        if (! is_array($output)) {
            return '';
        }

        $texts = [];

        foreach ($output as $item) {
            if (
                ! is_array($item)
                || ($item['type'] ?? null) !== 'message'
            ) {
                continue;
            }

            $content = $item['content'] ?? null;

            if (! is_array($content)) {
                continue;
            }

            foreach ($content as $part) {
                if (
                    ! is_array($part)
                    || ($part['type'] ?? null) !== 'output_text'
                ) {
                    continue;
                }

                $text = $part['text'] ?? null;

                if (is_string($text) && trim($text) !== '') {
                    $texts[] = trim($text);
                }
            }
        }

        return trim(
            implode("\n", $texts),
        );
    }

    private function languageName(Language $language): string
    {
        return match ($language) {
            Language::English => 'English',
            Language::Russian => 'Russian',
        };
    }
}
