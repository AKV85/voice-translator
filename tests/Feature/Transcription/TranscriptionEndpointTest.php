<?php

use App\Contracts\SpeechToTextProvider;
use App\DTO\AudioInput;
use App\DTO\TranscriptionResult;
use App\Enums\Language;
use App\Exceptions\SpeechRecognitionException;
use Illuminate\Http\UploadedFile;
use Tests\Fakes\FakeSpeechToTextProvider;

test('it transcribes uploaded audio', function () {
    $fake = new FakeSpeechToTextProvider(
        'I need a truck',
    );

    $this->app->bind(
        SpeechToTextProvider::class,
        fn () => $fake,
    );

    $audioContent = str_repeat('fake-audio-content', 100);

    $audio = UploadedFile::fake()
        ->createWithContent(
            'speech.webm',
            $audioContent,
        )
        ->mimeType('audio/webm');

    $response = $this->postJson(route('transcribe'), [
        'audio' => $audio,
        'language' => 'en',
    ]);

    $response
        ->assertOk()
        ->assertJson([
            'text' => 'I need a truck',
        ]);

    expect($fake->receivedLanguage)
        ->toBe(Language::English)
        ->and($fake->receivedAudio)
        ->not->toBeNull()
        ->and($fake->receivedAudio?->content)
        ->toBe($audioContent);
});

test('audio is required', function () {
    $response = $this->postJson(route('transcribe'), [
        'language' => 'en',
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors('audio');
});

test('language is required', function () {
    $response = $this->postJson(route('transcribe'), [
        'audio' => UploadedFile::fake()->create(
            'speech.webm',
            100,
            'audio/webm',
        ),
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors('language');
});

test('unsupported language is rejected', function () {
    $response = $this->postJson(route('transcribe'), [
        'audio' => UploadedFile::fake()->create(
            'speech.webm',
            100,
            'audio/webm',
        ),
        'language' => 'de',
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors('language');
});

test('provider error is returned as a safe response', function () {
    $this->app->bind(
        SpeechToTextProvider::class,
        fn () => new class implements SpeechToTextProvider
        {
            public function transcribe(
                AudioInput $audio,
                Language $sourceLanguage,
            ): TranscriptionResult {
                throw new SpeechRecognitionException(
                    'Sensitive Google provider error.',
                );
            }
        },
    );

    $audioContent = str_repeat('fake-audio-content', 100);

    $audio = UploadedFile::fake()
        ->createWithContent(
            'speech.webm',
            $audioContent,
        )
        ->mimeType('audio/webm');

    $response = $this->postJson(route('transcribe'), [
        'audio' => $audio,
        'language' => 'en',
    ]);

    $response
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'Speech could not be recognized.',
        ]);

    expect($response->getContent())
        ->not->toContain('Sensitive Google provider error.');
});
