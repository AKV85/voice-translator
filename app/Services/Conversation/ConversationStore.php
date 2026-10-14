<?php

namespace App\Services\Conversation;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

final class ConversationStore
{
    private const PREFIX = 'conversation:';

    /**
     * @return array{code: string, token: string}
     */
    public function create(): array
    {
        $ttl = $this->ttl();

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = Str::upper(Str::random(8));
            $token = Str::random(64);

            $created = Cache::add(
                $this->key($code),
                [
                    'code' => $code,
                    'status' => 'waiting',
                    'host' => [
                        'language' => 'ru',
                        'token_hash' => hash('sha256', $token),
                    ],
                    'guest' => null,
                    'expires_at' => now()
                        ->addSeconds($ttl)
                        ->toIso8601String(),
                ],
                $ttl,
            );

            if ($created) {
                return [
                    'code' => $code,
                    'token' => $token,
                ];
            }
        }

        throw new RuntimeException(
            'Could not allocate a conversation code.',
        );
    }

    /**
     * @return array{code: string, token: string}
     */
    public function join(string $code): array
    {
        $code = strtoupper(trim($code));

        return Cache::lock(
            'conversation:join:'.$code,
            10,
        )->block(3, function () use ($code): array {
            $conversation = $this->getActive($code);

            if ($conversation === null) {
                throw new RuntimeException(
                    'Conversation not found or expired.',
                );
            }

            if ($conversation['guest'] !== null) {
                throw new RuntimeException(
                    'Conversation is full.',
                );
            }

            $token = Str::random(64);

            $conversation['guest'] = [
                'language' => 'en',
                'token_hash' => hash('sha256', $token),
            ];

            $conversation['status'] = 'ready';

            Cache::put(
                $this->key($code),
                $conversation,
                Carbon::parse($conversation['expires_at']),
            );

            return [
                'code' => $code,
                'token' => $token,
            ];
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $code): ?array
    {
        $conversation = $this->getActive(
            strtoupper(trim($code)),
        );

        if ($conversation === null) {
            return null;
        }

        return [
            'code' => $conversation['code'],
            'status' => $conversation['status'],
            'host_language' => $conversation['host']['language'],
            'guest_language' => $conversation['guest']['language'] ?? null,
            'expires_at' => $conversation['expires_at'],
        ];
    }

    private function getActive(string $code): ?array
    {
        $conversation = Cache::get($this->key($code));

        if (! is_array($conversation)) {
            return null;
        }

        if (
            ! isset($conversation['expires_at'])
            || now()->greaterThanOrEqualTo(
                Carbon::parse($conversation['expires_at']),
            )
        ) {
            return null;
        }

        return $conversation;
    }

    private function ttl(): int
    {
        $ttl = (int) config('conversation.ttl_seconds');

        if ($ttl < 1) {
            throw new RuntimeException(
                'Conversation TTL must be positive.',
            );
        }

        return $ttl;
    }

    private function key(string $code): string
    {
        return self::PREFIX.$code;
    }
}
