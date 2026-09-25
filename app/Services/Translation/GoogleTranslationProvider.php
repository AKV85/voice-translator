<?php

namespace App\Services\Translation;

use App\Contracts\TranslationProvider;
use App\DTO\TranslationResult;
use App\Enums\Language;
use App\Exceptions\TranslationException;
use Google\Cloud\Translate\V3\Client\TranslationServiceClient;
use Google\Cloud\Translate\V3\TranslateTextRequest;
use Throwable;

final readonly class GoogleTranslationProvider implements TranslationProvider
{
    public function __construct(
        private TranslationServiceClient $client,
        private string $projectId,
        private string $location = 'global',
        private string $model = 'general/nmt',
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

        $parent = $this->client->locationName(
            $this->projectId,
            $this->location,
        );

        $model = sprintf(
            'projects/%s/locations/%s/models/%s',
            $this->projectId,
            $this->location,
            $this->model,
        );

        $request = (new TranslateTextRequest)
            ->setParent($parent)
            ->setContents([
                $text,
            ])
            ->setMimeType('text/plain')
            ->setSourceLanguageCode(
                $sourceLanguage->value,
            )
            ->setTargetLanguageCode(
                $targetLanguage->value,
            )
            ->setModel($model);

        try {
            $response = $this->client->translateText(
                $request,
            );
        } catch (Throwable $exception) {
            throw new TranslationException(
                'Translation provider failed.',
                previous: $exception,
            );
        }

        foreach ($response->getTranslations() as $translation) {
            $translatedText = trim(
                $translation->getTranslatedText(),
            );

            if ($translatedText !== '') {
                return new TranslationResult(
                    text: $translatedText,
                );
            }
        }

        throw new TranslationException(
            'Translation provider returned an empty translation.',
        );
    }
}
