<?php

use App\Enums\Language;
use App\Exceptions\SpeechSynthesisException;
use App\Services\Speech\OpenAIStreamingTextToSpeechProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('it streams pcm audio from openai speech api', function () {
    $audio = str_repeat(
        "\x01\x02",
        600,
    );

    Http::fake([
        'https://api.openai.test/v1/audio/speech' => Http::response(
            body: $audio,
            status: 200,
            headers: [
                'Content-Type' => 'application/octet-stream',
            ],
        ),
    ]);

    $provider = new OpenAIStreamingTextToSpeechProvider(
        http: app(Factory::class),
        apiKey: 'test-api-key',
        model: 'test-tts-model',
        endpoint: 'https://api.openai.test/v1/audio/speech',
        voice: 'marin',
    );

    $chunks = [];

    $result = $provider->synthesize(
        text: 'Leave trailer ZZ 546 in the parking area.',
        targetLanguage: Language::English,
        onAudioChunk: function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        },
    );

    expect($result->audio)
        ->toBe($audio)
        ->and($result->mimeType)
        ->toBe('audio/pcm')
        ->and(implode('', $chunks))
        ->toBe($audio)
        ->and(count($chunks))
        ->toBeGreaterThan(1);

    Http::assertSent(
        function (Request $request): bool {
            $data = $request->data();

            return $request->url()
                    === 'https://api.openai.test/v1/audio/speech'
                && $data['model'] === 'test-tts-model'
                && $data['input']
                    === 'Leave trailer ZZ 546 in the parking area.'
                && $data['voice'] === 'marin'
                && $data['response_format'] === 'pcm'
                && $data['stream_format'] === 'audio'
                && str_contains(
                    $data['instructions'],
                    'English',
                );
        },
    );
});

test('it rejects empty synthesis text', function () {
    Http::fake();

    $provider = new OpenAIStreamingTextToSpeechProvider(
        http: app(Factory::class),
        apiKey: 'test-api-key',
        model: 'test-tts-model',
        endpoint: 'https://api.openai.test/v1/audio/speech',
        voice: 'marin',
    );

    expect(
        fn () => $provider->synthesize(
            text: '   ',
            targetLanguage: Language::English,
            onAudioChunk: function (string $chunk): void {},
        ),
    )->toThrow(
        SpeechSynthesisException::class,
        'Speech synthesis text must not be empty.',
    );

    Http::assertNothingSent();
});
