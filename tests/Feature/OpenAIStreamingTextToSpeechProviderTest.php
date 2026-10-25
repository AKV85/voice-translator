<?php

use App\Enums\Language;
use App\Exceptions\SpeechSynthesisException;
use App\Services\Speech\OpenAIStreamingTextToSpeechProvider;
use GuzzleHttp\Psr7\Utils;
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

test('it observes request, headers and first audio in order without changing PCM', function (): void {
    $audio = str_repeat("\x01\x02", 600);
    Http::fake(['api.openai.test/*' => Http::response($audio)]);
    $events = [];
    $provider = new OpenAIStreamingTextToSpeechProvider(app(Factory::class), 'test-key', 'test-model', 'https://api.openai.test/speech', 'marin');
    $provider = $provider->withTimingObserver(function (string $stage, int $at) use (&$events): void {
        $events[$stage] = $at;
    });
    $parts = [];
    $result = $provider->synthesize('Hello', Language::English, function (string $chunk) use (&$parts): void {
        $parts[] = $chunk;
    });
    expect(array_keys($events))->toBe(['request', 'headers', 'first_audio'])
        ->and($events['headers'])->toBeGreaterThanOrEqual($events['request'])
        ->and($events['first_audio'])->toBeGreaterThanOrEqual($events['headers'])
        ->and(implode('', $parts))->toBe($audio)
        ->and($result->audio)->toBe($audio);
});

test('it closes the streaming body when downstream delivery is cancelled', function (): void {
    $stream = Utils::streamFor(str_repeat("\x01\x02", 600));
    Http::fake(fn () => Http::response($stream));
    $provider = new OpenAIStreamingTextToSpeechProvider(app(Factory::class), 'test-key', 'test-model', 'https://api.openai.test/speech', 'marin');
    $chunks = 0;
    try {
        $provider->synthesize('Hello', Language::English, function () use (&$chunks): void {
            $chunks++;
            throw new RuntimeException('Cancelled');
        });
        $this->fail('Expected cancellation');
    } catch (SpeechSynthesisException $exception) {
        expect($exception->getPrevious()?->getMessage())->toBe('Cancelled');
    }
    expect($chunks)->toBe(1)->and($stream->isReadable())->toBeFalse();
});
