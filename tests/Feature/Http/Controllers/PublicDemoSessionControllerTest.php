<?php

use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    config([
        'public_demo.enabled' => true,

        'public_demo.token_ttl_seconds' => 60,

        'public_demo.max_audio_seconds' => 15,

        'public_demo.max_audio_bytes' => 2_000_000,

        'public_demo.hourly_limit' => 5,

        'public_demo.daily_limit' => 15,

        'public_demo.ip_daily_limit' => 30,

        'public_demo.global_daily_limit' => 200,

        'public_demo.profiles.ru' => 'chirp3-streaming-standard-deepl-openai',

        'public_demo.profiles.en' => 'chirp3-streaming-short-deepl-openai',
    ]);
});

it('issues a public demo token', function (): void {
    $response =
        $this
            ->withServerVariables([
                'REMOTE_ADDR' => '203.0.113.10',
            ])
            ->withSession([
                'public_demo_visitor_id' => 'visitor-one',
            ])
            ->postJson(
                route(
                    'demo.session.store',
                ),
                [
                    'source_language' => 'ru',
                ],
            );

    $response
        ->assertCreated()
        ->assertJsonPath(
            'source_language',
            'ru',
        )
        ->assertJsonPath(
            'target_language',
            'en',
        )
        ->assertJsonPath(
            'expires_in',
            60,
        )
        ->assertJsonPath(
            'max_audio_seconds',
            15,
        )
        ->assertJsonPath(
            'usage.hourly_remaining',
            4,
        )
        ->assertJsonPath(
            'usage.daily_remaining',
            14,
        )
        ->assertJsonStructure([
            'token',
        ]);

    $token =
        $response->json(
            'token',
        );

    expect($token)
        ->toBeString()
        ->not
        ->toBe('');

    $stored =
        Cache::get(
            'public-demo:token:'
                .hash(
                    'sha256',
                    $token,
                ),
        );

    expect($stored)
        ->toBeArray()
        ->and(
            $stored['source_language'],
        )
        ->toBe('ru')
        ->and(
            $stored['profile'],
        )
        ->toBe(
            'chirp3-streaming-standard-deepl-openai',
        )
        ->and(
            $stored['max_audio_seconds'],
        )
        ->toBe(15)
        ->and(
            $stored['max_audio_bytes'],
        )
        ->toBe(2_000_000);
});

it('uses the configured English demo profile', function (): void {
    $response =
        $this
            ->withServerVariables([
                'REMOTE_ADDR' => '203.0.113.11',
            ])
            ->withSession([
                'public_demo_visitor_id' => 'visitor-two',
            ])
            ->postJson(
                route(
                    'demo.session.store',
                ),
                [
                    'source_language' => 'en',
                ],
            );

    $response->assertCreated();

    $token =
        $response->json(
            'token',
        );

    $stored =
        Cache::get(
            'public-demo:token:'
                .hash(
                    'sha256',
                    $token,
                ),
        );

    expect(
        $stored['profile'],
    )->toBe(
        'chirp3-streaming-short-deepl-openai',
    );
});

it('rejects requests when the public demo is disabled', function (): void {
    config([
        'public_demo.enabled' => false,
    ]);

    $this
        ->postJson(
            route(
                'demo.session.store',
            ),
            [
                'source_language' => 'ru',
            ],
        )
        ->assertServiceUnavailable()
        ->assertJson([
            'message' => 'Public voice demo is temporarily unavailable.',
        ]);
});

it('rejects an unsupported source language', function (): void {
    $this
        ->postJson(
            route(
                'demo.session.store',
            ),
            [
                'source_language' => 'lt',
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'source_language',
        ]);
});

it('prevents multiple active demo sessions for one visitor', function (): void {
    $request =
        $this
            ->withServerVariables([
                'REMOTE_ADDR' => '203.0.113.12',
            ])
            ->withSession([
                'public_demo_visitor_id' => 'visitor-three',
            ]);

    $request
        ->postJson(
            route(
                'demo.session.store',
            ),
            [
                'source_language' => 'ru',
            ],
        )
        ->assertCreated();

    $request
        ->postJson(
            route(
                'demo.session.store',
            ),
            [
                'source_language' => 'ru',
            ],
        )
        ->assertConflict()
        ->assertJson([
            'message' => 'A demo translation is already active.',
        ]);
});

it('enforces the hourly visitor limit', function (): void {
    config([
        'public_demo.hourly_limit' => 1,
    ]);

    $ip =
        '203.0.113.13';

    $visitorId =
        'visitor-four';

    $request =
        $this
            ->withServerVariables([
                'REMOTE_ADDR' => $ip,
            ])
            ->withSession([
                'public_demo_visitor_id' => $visitorId,
            ]);

    $request
        ->postJson(
            route(
                'demo.session.store',
            ),
            [
                'source_language' => 'ru',
            ],
        )
        ->assertCreated();

    $visitorKey =
        hash(
            'sha256',
            $ip.'|'.$visitorId,
        );

    Cache::forget(
        'public-demo:active:'
            .$visitorKey,
    );

    $request
        ->postJson(
            route(
                'demo.session.store',
            ),
            [
                'source_language' => 'ru',
            ],
        )
        ->assertTooManyRequests()
        ->assertJson([
            'message' => 'Public demo usage limit reached.',
        ]);
});
