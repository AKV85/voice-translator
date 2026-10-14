<?php

use App\Services\Conversation\ConversationStore;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();

    config()->set('conversation.ttl_seconds', 1800);
});

it('creates a conversation with a Russian host', function (): void {
    $store = app(ConversationStore::class);

    $created = $store->create();
    $conversation = $store->find($created['code']);

    expect($created['code'])->toHaveLength(8)
        ->and($created['token'])->toHaveLength(64)
        ->and($conversation['status'])->toBe('waiting')
        ->and($conversation['host_language'])->toBe('ru')
        ->and($conversation['guest_language'])->toBeNull();
});

it('allows one English-speaking guest to join', function (): void {
    $store = app(ConversationStore::class);

    $host = $store->create();
    $guest = $store->join($host['code']);

    $conversation = $store->find($host['code']);

    expect($guest['token'])->not->toBe($host['token'])
        ->and($conversation['status'])->toBe('ready')
        ->and($conversation['guest_language'])->toBe('en');
});

it('rejects a third participant', function (): void {
    $store = app(ConversationStore::class);

    $host = $store->create();

    $store->join($host['code']);

    expect(
        fn () => $store->join($host['code']),
    )->toThrow(RuntimeException::class, 'Conversation is full.');
});

it('does not expose authentication hashes', function (): void {
    $store = app(ConversationStore::class);

    $host = $store->create();

    $conversation = $store->find($host['code']);

    expect($conversation)
        ->not->toHaveKey('host')
        ->not->toHaveKey('guest')
        ->not->toHaveKey('token_hash');
});

it('expires a conversation after its TTL', function (): void {
    config()->set('conversation.ttl_seconds', 60);

    $store = app(ConversationStore::class);

    $host = $store->create();

    $this->travel(61)->seconds();

    expect($store->find($host['code']))->toBeNull();
});
