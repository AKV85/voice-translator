<?php

use App\Enums\Language;
use App\Services\Speech\BatchSpeechToTextStreamingAdapter;
use Tests\Fakes\FakeSpeechToTextProvider;

it('adapts streamed audio chunks to a batch speech-to-text provider', function () {
    $batchProvider = new FakeSpeechToTextProvider(
        transcription: 'I need a truck',
    );

    $provider = new BatchSpeechToTextStreamingAdapter(
        batchProvider: $batchProvider,
    );

    $events = [];

    $result = $provider->transcribe(
        audioChunks: [
            'first-',
            'second-',
            'third',
        ],
        mimeType: 'audio/webm',
        sourceLanguage: Language::English,
        onTranscript: function (
            string $text,
            bool $isFinal,
        ) use (&$events): void {
            $events[] = [
                'text' => $text,
                'is_final' => $isFinal,
            ];
        },
    );

    expect($batchProvider->receivedAudio)
        ->not->toBeNull()
        ->and($batchProvider->receivedAudio?->content)
        ->toBe('first-second-third')
        ->and($batchProvider->receivedAudio?->mimeType)
        ->toBe('audio/webm')
        ->and($batchProvider->receivedLanguage)
        ->toBe(Language::English)
        ->and($result->text)
        ->toBe('I need a truck')
        ->and($events)
        ->toBe([
            [
                'text' => 'I need a truck',
                'is_final' => true,
            ],
        ]);
});
