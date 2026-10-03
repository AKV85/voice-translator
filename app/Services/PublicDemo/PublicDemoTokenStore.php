<?php

namespace App\Services\PublicDemo;

use App\Enums\Language;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class PublicDemoTokenStore
{
    private const TOKEN_PREFIX =
        'public-demo:token:';

    private const ACTIVE_PREFIX =
        'public-demo:active:';

    public function hasActive(
        string $visitorKey,
    ): bool {
        return Cache::has(
            $this->activeKey(
                $visitorKey,
            ),
        );
    }

    public function issue(
        string $visitorKey,
        Language $sourceLanguage,
        string $profile,
        int $ttlSeconds,
        int $maxAudioSeconds,
        int $maxAudioBytes,
    ): string {
        $token =
            Str::random(64);

        $tokenHash =
            hash(
                'sha256',
                $token,
            );

        $expiresAt =
            now()->addSeconds(
                $ttlSeconds,
            );

        Cache::put(
            $this->tokenKey(
                $tokenHash,
            ),
            [
                'visitor_key' => $visitorKey,

                'source_language' => $sourceLanguage->value,

                'profile' => $profile,

                'max_audio_seconds' => $maxAudioSeconds,

                'max_audio_bytes' => $maxAudioBytes,

                'issued_at' => now()
                    ->toIso8601String(),

                'expires_at' => $expiresAt
                    ->toIso8601String(),
            ],
            $expiresAt,
        );

        Cache::put(
            $this->activeKey(
                $visitorKey,
            ),
            $tokenHash,
            $expiresAt,
        );

        return $token;
    }

    /**
     * @return array{
     *     visitor_key: string,
     *     source_language: string,
     *     profile: string,
     *     max_audio_seconds: int,
     *     max_audio_bytes: int,
     *     token_hash: string
     * }|null
     */
    public function consume(
        string $token,
    ): ?array {
        if ($token === '') {
            return null;
        }

        $tokenHash =
            hash(
                'sha256',
                $token,
            );

        $payload =
            Cache::pull(
                $this->tokenKey(
                    $tokenHash,
                ),
            );

        if (! is_array($payload)) {
            return null;
        }

        $visitorKey =
            $payload['visitor_key']
            ?? null;

        $sourceLanguage =
            $payload['source_language']
            ?? null;

        $profile =
            $payload['profile']
            ?? null;

        $maxAudioSeconds =
            $payload['max_audio_seconds']
            ?? null;

        $maxAudioBytes =
            $payload['max_audio_bytes']
            ?? null;

        if (
            ! is_string($visitorKey)
            || $visitorKey === ''
            || ! is_string($sourceLanguage)
            || $sourceLanguage === ''
            || ! is_string($profile)
            || $profile === ''
            || ! is_int($maxAudioSeconds)
            || $maxAudioSeconds < 1
            || ! is_int($maxAudioBytes)
            || $maxAudioBytes < 1
        ) {
            return null;
        }

        $activeTokenHash =
            Cache::get(
                $this->activeKey(
                    $visitorKey,
                ),
            );

        if (
            ! is_string($activeTokenHash)
            || ! hash_equals(
                $activeTokenHash,
                $tokenHash,
            )
        ) {
            return null;
        }

        return [
            'visitor_key' => $visitorKey,

            'source_language' => $sourceLanguage,

            'profile' => $profile,

            'max_audio_seconds' => $maxAudioSeconds,

            'max_audio_bytes' => $maxAudioBytes,

            'token_hash' => $tokenHash,
        ];
    }

    public function release(
        string $visitorKey,
        string $tokenHash,
    ): void {
        $activeKey =
            $this->activeKey(
                $visitorKey,
            );

        $activeTokenHash =
            Cache::get(
                $activeKey,
            );

        if (
            ! is_string($activeTokenHash)
            || ! hash_equals(
                $activeTokenHash,
                $tokenHash,
            )
        ) {
            return;
        }

        Cache::forget(
            $activeKey,
        );
    }

    private function tokenKey(
        string $tokenHash,
    ): string {
        return self::TOKEN_PREFIX
            .$tokenHash;
    }

    private function activeKey(
        string $visitorKey,
    ): string {
        return self::ACTIVE_PREFIX
            .$visitorKey;
    }
}
