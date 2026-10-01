<?php

use App\Enums\Language;
use App\Exceptions\PublicDemoException;
use App\Services\PublicDemo\PublicDemoTokenStore;
use App\Services\PublicDemo\PublicDemoWebSocketSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();

    Carbon::setTestNow(
        Carbon::parse(
            '2026-10-01 12:00:00',
        ),
    );
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('creates a public WebSocket session from a demo token', function (): void {
    $tokenStore =
        app(
            PublicDemoTokenStore::class,
        );

    $token =
        $tokenStore->issue(
            visitorKey: 'visitor-one',

            sourceLanguage: Language::Russian,

            profile: 'chirp3-streaming-standard-deepl-openai',

            ttlSeconds: 60,

            maxAudioSeconds: 15,

            maxAudioBytes: 2_000_000,
        );

    $session =
        PublicDemoWebSocketSession::consume(
            tokenStore: $tokenStore,

            token: $token,
        );

    expect(
        $session->sourceLanguage(),
    )
        ->toBe(
            Language::Russian,
        )
        ->and(
            $session->profile(),
        )
        ->toBe(
            'chirp3-streaming-standard-deepl-openai',
        )
        ->and(
            $session->maxAudioSeconds(),
        )
        ->toBe(15)
        ->and(
            $session->maxAudioBytes(),
        )
        ->toBe(2_000_000);
});

it('cannot consume the same demo token twice', function (): void {
    $tokenStore =
        app(
            PublicDemoTokenStore::class,
        );

    $token =
        $tokenStore->issue(
            visitorKey: 'visitor-two',

            sourceLanguage: Language::English,

            profile: 'chirp3-streaming-short-deepl-openai',

            ttlSeconds: 60,

            maxAudioSeconds: 15,

            maxAudioBytes: 2_000_000,
        );

    PublicDemoWebSocketSession::consume(
        tokenStore: $tokenStore,

        token: $token,
    );

    expect(
        fn () => PublicDemoWebSocketSession::consume(
            tokenStore: $tokenStore,

            token: $token,
        ),
    )->toThrow(
        PublicDemoException::class,
        'Invalid or expired public demo token.',
    );
});

it('enforces the public demo audio size limit', function (): void {
    $tokenStore =
        app(
            PublicDemoTokenStore::class,
        );

    $token =
        $tokenStore->issue(
            visitorKey: 'visitor-three',

            sourceLanguage: Language::Russian,

            profile: 'chirp3-streaming-standard-deepl-openai',

            ttlSeconds: 60,

            maxAudioSeconds: 15,

            maxAudioBytes: 4,
        );

    $session =
        PublicDemoWebSocketSession::consume(
            tokenStore: $tokenStore,

            token: $token,
        );

    $session->acceptAudioChunk(
        '1234',
    );

    expect(
        $session->receivedAudioBytes(),
    )->toBe(4);

    expect(
        fn () => $session->acceptAudioChunk(
            '5',
        ),
    )->toThrow(
        PublicDemoException::class,
        'Public demo audio size limit exceeded.',
    );
});

it('enforces the public demo recording duration limit', function (): void {
    $tokenStore =
        app(
            PublicDemoTokenStore::class,
        );

    $token =
        $tokenStore->issue(
            visitorKey: 'visitor-four',

            sourceLanguage: Language::Russian,

            profile: 'chirp3-streaming-standard-deepl-openai',

            ttlSeconds: 60,

            maxAudioSeconds: 15,

            maxAudioBytes: 2_000_000,
        );

    $session =
        PublicDemoWebSocketSession::consume(
            tokenStore: $tokenStore,

            token: $token,
        );

    $session->acceptAudioChunk(
        'audio',
    );

    Carbon::setTestNow(
        now()->addSeconds(16),
    );

    expect(
        fn () => $session->finishRecording(),
    )->toThrow(
        PublicDemoException::class,
        'Public demo recording duration limit exceeded.',
    );
});

it('releases the visitor lock when the WebSocket session ends', function (): void {
    $tokenStore =
        app(
            PublicDemoTokenStore::class,
        );

    $token =
        $tokenStore->issue(
            visitorKey: 'visitor-five',

            sourceLanguage: Language::English,

            profile: 'chirp3-streaming-short-deepl-openai',

            ttlSeconds: 60,

            maxAudioSeconds: 15,

            maxAudioBytes: 2_000_000,
        );

    $session =
        PublicDemoWebSocketSession::consume(
            tokenStore: $tokenStore,

            token: $token,
        );

    expect(
        $tokenStore->hasActive(
            'visitor-five',
        ),
    )->toBeTrue();

    $session->release();

    expect(
        $tokenStore->hasActive(
            'visitor-five',
        ),
    )->toBeFalse();

    $session->release();

    expect(
        $tokenStore->hasActive(
            'visitor-five',
        ),
    )->toBeFalse();
});
