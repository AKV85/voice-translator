<?php

use App\Contracts\StreamingTextToSpeechProvider;
use App\Contracts\TranslationProvider;
use App\DTO\SpeechSynthesisResult;
use App\DTO\TranslationResult;
use App\Enums\Language;
use App\Services\Conversation\ConversationPipelineProcessor;

it('translates and synthesizes conversation speech', function (): void {
    $calls = [];

    $translation = new class($calls) implements TranslationProvider
    {
        public function __construct(
            private array &$calls,
        ) {}

        public function translate(
            string $text,
            Language $sourceLanguage,
            Language $targetLanguage,
        ): TranslationResult {
            $this->calls[] = [
                'translation',
                $text,
                $sourceLanguage->value,
                $targetLanguage->value,
            ];

            return new TranslationResult('Hello from the worker.');
        }
    };

    $tts = new class($calls) implements StreamingTextToSpeechProvider
    {
        public function __construct(
            private array &$calls,
        ) {}

        public function synthesize(
            string $text,
            Language $targetLanguage,
            Closure $onAudioChunk,
        ): SpeechSynthesisResult {
            $this->calls[] = [
                'tts',
                $text,
                $targetLanguage->value,
            ];

            $onAudioChunk("\x01\x02");
            $onAudioChunk("\x03\x04");

            return new SpeechSynthesisResult(
                audio: "\x01\x02\x03\x04",
                mimeType: 'audio/pcm',
            );
        }
    };

    $processor = new ConversationPipelineProcessor(
        translation: $translation,
        textToSpeech: $tts,
    );

    $result = $processor->process(
        sourceText: 'Привет!',
        sourceLanguage: Language::from('ru'),
        targetLanguage: Language::from('en'),
    );

    expect($result)->toBe([
        'translated_text' => 'Hello from the worker.',
        'audio' => "\x01\x02\x03\x04",
        'mime_type' => 'audio/pcm',
    ]);

    expect($calls)->toBe([
        ['translation', 'Привет!', 'ru', 'en'],
        ['tts', 'Hello from the worker.', 'en'],
    ]);
});

it('propagates translation failures without starting TTS', function (): void {
    $translation = Mockery::mock(TranslationProvider::class);
    $tts = Mockery::mock(StreamingTextToSpeechProvider::class);

    $translation
        ->shouldReceive('translate')
        ->once()
        ->andThrow(new RuntimeException('Translation failed.'));

    $tts->shouldNotReceive('synthesize');

    $processor = new ConversationPipelineProcessor(
        translation: $translation,
        textToSpeech: $tts,
    );

    expect(fn () => $processor->process(
        sourceText: 'Привет!',
        sourceLanguage: Language::from('ru'),
        targetLanguage: Language::from('en'),
    ))->toThrow(
        RuntimeException::class,
        'Translation failed.',
    );
});
