<?php

use App\Enums\Language;
use App\Services\PublicDemo\PublicDemoTokenStore;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
});

it('consumes a public demo token only once', function (): void {
    $store =
        app(
            PublicDemoTokenStore::class,
        );

    $token =
        $store->issue(
            visitorKey: 'visitor-one',
            sourceLanguage: Language::Russian,
            profile: 'chirp3-streaming-standard-deepl-openai',
            ttlSeconds: 60,
            maxAudioSeconds: 15,
            maxAudioBytes: 2_000_000,
        );

    $tokenHash =
        hash(
            'sha256',
            $token,
        );

    $context =
        $store->consume(
            $token,
        );

    expect($context)
        ->toBeArray()
        ->and(
            $context['visitor_key'],
        )
        ->toBe(
            'visitor-one',
        )
        ->and(
            $context['source_language'],
        )
        ->toBe('ru')
        ->and(
            $context['profile'],
        )
        ->toBe(
            'chirp3-streaming-standard-deepl-openai',
        )
        ->and(
            $context['max_audio_seconds'],
        )
        ->toBe(15)
        ->and(
            $context['max_audio_bytes'],
        )
        ->toBe(2_000_000)
        ->and(
            $context['token_hash'],
        )
        ->toBe(
            $tokenHash,
        );

    expect(
        $store->consume(
            $token,
        ),
    )->toBeNull();
});

it('keeps the visitor active after consuming the token', function (): void {
    $store =
        app(
            PublicDemoTokenStore::class,
        );

    $token =
        $store->issue(
            visitorKey: 'visitor-two',
            sourceLanguage: Language::English,
            profile: 'chirp3-streaming-short-deepl-openai',
            ttlSeconds: 60,
            maxAudioSeconds: 15,
            maxAudioBytes: 2_000_000,
        );

    $store->consume(
        $token,
    );

    expect(
        $store->hasActive(
            'visitor-two',
        ),
    )->toBeTrue();
});

it('releases an active public demo session', function (): void {
    $store =
        app(
            PublicDemoTokenStore::class,
        );

    $token =
        $store->issue(
            visitorKey: 'visitor-three',
            sourceLanguage: Language::Russian,
            profile: 'chirp3-streaming-standard-deepl-openai',
            ttlSeconds: 60,
            maxAudioSeconds: 15,
            maxAudioBytes: 2_000_000,
        );

    $context =
        $store->consume(
            $token,
        );

    expect($context)
        ->toBeArray();

    $store->release(
        visitorKey: $context['visitor_key'],
        tokenHash: $context['token_hash'],
    );

    expect(
        $store->hasActive(
            'visitor-three',
        ),
    )->toBeFalse();
});

it('does not release a different active token', function (): void {
    $store =
        app(
            PublicDemoTokenStore::class,
        );

    $store->issue(
        visitorKey: 'visitor-four',
        sourceLanguage: Language::Russian,
        profile: 'chirp3-streaming-standard-deepl-openai',
        ttlSeconds: 60,
        maxAudioSeconds: 15,
        maxAudioBytes: 2_000_000,
    );

    $store->release(
        visitorKey: 'visitor-four',
        tokenHash: hash(
            'sha256',
            'wrong-token',
        ),
    );

    expect(
        $store->hasActive(
            'visitor-four',
        ),
    )->toBeTrue();
});
