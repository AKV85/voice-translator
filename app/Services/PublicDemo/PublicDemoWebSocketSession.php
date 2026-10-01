<?php

namespace App\Services\PublicDemo;

use App\Enums\Language;
use App\Exceptions\PublicDemoException;
use Illuminate\Support\Carbon;

final class PublicDemoWebSocketSession
{
    private int $receivedAudioBytes = 0;

    private ?Carbon $audioStartedAt = null;

    private bool $released = false;

    private function __construct(
        private readonly PublicDemoTokenStore $tokenStore,
        private readonly string $visitorKey,
        private readonly string $tokenHash,
        private readonly Language $sourceLanguage,
        private readonly string $profile,
        private readonly int $maxAudioSeconds,
        private readonly int $maxAudioBytes,
    ) {}

    public static function consume(
        PublicDemoTokenStore $tokenStore,
        string $token,
    ): self {
        $context =
            $tokenStore->consume(
                $token,
            );

        if ($context === null) {
            throw new PublicDemoException(
                'Invalid or expired public demo token.',
            );
        }

        $sourceLanguage =
            Language::tryFrom(
                $context['source_language'],
            );

        if ($sourceLanguage === null) {
            $tokenStore->release(
                visitorKey: $context['visitor_key'],
                tokenHash: $context['token_hash'],
            );

            throw new PublicDemoException(
                'Invalid public demo session.',
            );
        }

        return new self(
            tokenStore: $tokenStore,

            visitorKey: $context['visitor_key'],

            tokenHash: $context['token_hash'],

            sourceLanguage: $sourceLanguage,

            profile: $context['profile'],

            maxAudioSeconds: $context['max_audio_seconds'],

            maxAudioBytes: $context['max_audio_bytes'],
        );
    }

    public function sourceLanguage(): Language
    {
        return $this->sourceLanguage;
    }

    public function profile(): string
    {
        return $this->profile;
    }

    public function maxAudioSeconds(): int
    {
        return $this->maxAudioSeconds;
    }

    public function maxAudioBytes(): int
    {
        return $this->maxAudioBytes;
    }

    public function receivedAudioBytes(): int
    {
        return $this->receivedAudioBytes;
    }

    public function acceptAudioChunk(
        string $audio,
    ): void {
        if ($audio === '') {
            return;
        }

        if ($this->audioStartedAt === null) {
            $this->audioStartedAt =
                now();
        }

        $this->assertDurationLimit();

        $newSize =
            $this->receivedAudioBytes
            + strlen($audio);

        if (
            $newSize
            > $this->maxAudioBytes
        ) {
            throw new PublicDemoException(
                'Public demo audio size limit exceeded.',
            );
        }

        $this->receivedAudioBytes =
            $newSize;
    }

    public function finishRecording(): void
    {
        $this->assertDurationLimit();
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->tokenStore->release(
            visitorKey: $this->visitorKey,

            tokenHash: $this->tokenHash,
        );

        $this->released = true;
    }

    private function assertDurationLimit(): void
    {
        if ($this->audioStartedAt === null) {
            return;
        }

        $limitAt =
            $this->audioStartedAt
                ->copy()
                ->addSeconds(
                    $this->maxAudioSeconds,
                );

        if (now()->greaterThan($limitAt)) {
            throw new PublicDemoException(
                'Public demo recording duration limit exceeded.',
            );
        }
    }
}
