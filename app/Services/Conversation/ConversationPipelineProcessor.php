<?php

namespace App\Services\Conversation;

use App\Contracts\StreamingTextToSpeechProvider;
use App\Contracts\TranslationProvider;
use App\Enums\Language;

final readonly class ConversationPipelineProcessor
{
    public function __construct(
        private TranslationProvider $translation,
        private StreamingTextToSpeechProvider $textToSpeech,
    ) {}

    /**
     * @return array{
     *     translated_text: string,
     *     audio: string,
     *     mime_type: string
     * }
     */
    public function process(
        string $sourceText,
        Language $sourceLanguage,
        Language $targetLanguage,
    ): array {
        $translationResult = $this->translation->translate(
            text: $sourceText,
            sourceLanguage: $sourceLanguage,
            targetLanguage: $targetLanguage,
        );

        $synthesisResult = $this->textToSpeech->synthesize(
            text: $translationResult->text,
            targetLanguage: $targetLanguage,
            onAudioChunk: static function (string $chunk): void {
                // The synthesis result contains the complete audio.
                // IPC serialization will happen in the worker entrypoint.
            },
        );

        return [
            'translated_text' => $translationResult->text,
            'audio' => $synthesisResult->audio,
            'mime_type' => $synthesisResult->mimeType,
        ];
    }
}
