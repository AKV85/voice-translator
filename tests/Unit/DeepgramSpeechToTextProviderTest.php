<?php

use App\DTO\AudioInput;
use App\Enums\Language;
use App\Exceptions\SpeechRecognitionException;
use App\Services\Speech\DeepgramSpeechToTextProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;

it('transcribes Russian audio using Deepgram Nova 3', function () {
    $http = new Factory;

    $http->fake([
        'https://api.deepgram.com/v1/listen*' => $http->response(
            [
                'results' => [
                    'channels' => [
                        [
                            'alternatives' => [
                                [
                                    'transcript' => 'Привет из Deepgram',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            200,
        ),
    ]);

    $provider = new DeepgramSpeechToTextProvider(
        http: $http,
        apiKey: 'test-api-key',
        endpoint: 'https://api.deepgram.com/v1/listen',
        model: 'nova-3',
    );

    $result = $provider->transcribe(
        audio: new AudioInput(
            content: 'FAKE_AUDIO',
            mimeType: 'audio/webm',
        ),
        sourceLanguage: Language::Russian,
    );

    expect($result->text)
        ->toBe('Привет из Deepgram');

    $http->assertSent(function (Request $request): bool {
        parse_str(
            parse_url(
                $request->url(),
                PHP_URL_QUERY,
            ) ?? '',
            $query,
        );

        return
            str_starts_with(
                $request->url(),
                'https://api.deepgram.com/v1/listen',
            )
            && ($query['model'] ?? null) === 'nova-3'
            && ($query['language'] ?? null) === 'ru'
            && $request->hasHeader(
                'Authorization',
                'Token test-api-key',
            )
            && $request->hasHeader(
                'Content-Type',
                'audio/webm',
            )
            && $request->body() === 'FAKE_AUDIO';
    });
});

it('uses English language for English audio', function () {
    $http = new Factory;

    $http->fake([
        'https://api.deepgram.com/v1/listen*' => $http->response(
            [
                'results' => [
                    'channels' => [
                        [
                            'alternatives' => [
                                [
                                    'transcript' => 'Hello from Deepgram',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            200,
        ),
    ]);

    $provider = new DeepgramSpeechToTextProvider(
        http: $http,
        apiKey: 'test-api-key',
        endpoint: 'https://api.deepgram.com/v1/listen',
        model: 'nova-3',
    );

    $provider->transcribe(
        audio: new AudioInput(
            content: 'FAKE_AUDIO',
            mimeType: 'audio/webm',
        ),
        sourceLanguage: Language::English,
    );

    $http->assertSent(function (Request $request): bool {
        parse_str(
            parse_url(
                $request->url(),
                PHP_URL_QUERY,
            ) ?? '',
            $query,
        );

        return ($query['language'] ?? null) === 'en';
    });
});

it('wraps Deepgram API failures', function () {
    $http = new Factory;

    $http->fake([
        'https://api.deepgram.com/v1/listen*' => $http->response(
            [
                'err_code' => 'INVALID_AUTH',
                'err_msg' => 'Invalid credentials.',
            ],
            401,
        ),
    ]);

    $provider = new DeepgramSpeechToTextProvider(
        http: $http,
        apiKey: 'invalid-key',
        endpoint: 'https://api.deepgram.com/v1/listen',
        model: 'nova-3',
    );

    expect(fn () => $provider->transcribe(
        audio: new AudioInput(
            content: 'FAKE_AUDIO',
            mimeType: 'audio/webm',
        ),
        sourceLanguage: Language::English,
    ))->toThrow(SpeechRecognitionException::class);
});

it('fails when Deepgram returns an empty transcript', function () {
    $http = new Factory;

    $http->fake([
        'https://api.deepgram.com/v1/listen*' => $http->response(
            [
                'results' => [
                    'channels' => [
                        [
                            'alternatives' => [
                                [
                                    'transcript' => '',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            200,
        ),
    ]);

    $provider = new DeepgramSpeechToTextProvider(
        http: $http,
        apiKey: 'test-api-key',
        endpoint: 'https://api.deepgram.com/v1/listen',
        model: 'nova-3',
    );

    expect(fn () => $provider->transcribe(
        audio: new AudioInput(
            content: 'FAKE_AUDIO',
            mimeType: 'audio/webm',
        ),
        sourceLanguage: Language::English,
    ))->toThrow(
        SpeechRecognitionException::class,
        'No speech could be recognized.',
    );
});
