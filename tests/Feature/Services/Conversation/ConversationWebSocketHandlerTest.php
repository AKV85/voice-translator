<?php

use App\Services\Conversation\ConversationConnectionRegistry;
use App\Services\Conversation\ConversationStore;
use App\Services\Conversation\ConversationWebSocketHandler;
use WebSocket\Connection;

beforeEach(function (): void {
    config()->set('conversation.enabled', true);
    config()->set('conversation.ttl_seconds', 1800);

    Cache::store('array')->flush();
    config()->set('cache.default', 'array');
});

function conversationHandler(): ConversationWebSocketHandler
{
    return new ConversationWebSocketHandler(
        app(ConversationStore::class),
        new ConversationConnectionRegistry,
    );
}

it('authenticates a host and sends a joined event', function (): void {
    $host = app(ConversationStore::class)->create();
    $handler = conversationHandler();
    $connection = Mockery::mock(Connection::class);
    $events = [];

    $handler->join(
        $connection,
        $host['code'],
        $host['token'],
        function ($to, $payload) use (&$events): void {
            $events[] = [$to, $payload];
        },
    );

    expect($handler->isCurrent($connection))->toBeTrue()
        ->and($events[0][0])->toBe($connection)
        ->and($events[0][1]['type'])->toBe('conversation_joined')
        ->and($events[0][1]['role'])->toBe('host')
        ->and($events[0][1]['peer_connected'])->toBeFalse();
});

it('rejects invalid credentials without registering a socket', function (): void {
    $host = app(ConversationStore::class)->create();
    $handler = conversationHandler();
    $connection = Mockery::mock(Connection::class);

    expect(fn () => $handler->join(
        $connection,
        $host['code'],
        'invalid',
        fn () => null,
    ))->toThrow(InvalidArgumentException::class);

    expect($handler->isBound($connection))->toBeFalse();
});

it('notifies both participants when the guest connects', function (): void {
    $store = app(ConversationStore::class);
    $host = $store->create();
    $guest = $store->join($host['code']);

    $handler = conversationHandler();
    $hostConnection = Mockery::mock(Connection::class);
    $guestConnection = Mockery::mock(Connection::class);

    $hostConnection->shouldReceive('isConnected')
        ->andReturn(true);

    $events = [];

    $send = function ($to, $payload) use (&$events): void {
        $events[] = [$to, $payload];
    };

    $handler->join(
        $hostConnection, $host['code'], $host['token'], $send,
    );

    $handler->join(
        $guestConnection, $guest['code'], $guest['token'], $send,
    );

    expect($handler->isCurrent($hostConnection))->toBeTrue()
        ->and($handler->isCurrent($guestConnection))->toBeTrue()
        ->and($events[1][1]['peer_connected'])->toBeTrue()
        ->and($events[2][0])->toBe($hostConnection)
        ->and($events[2][1]['type'])->toBe('conversation_peer_joined');
});

it('preserves the previous connection if acknowledgement fails', function (): void {
    $host = app(ConversationStore::class)->create();
    $handler = conversationHandler();

    $old = Mockery::mock(Connection::class);
    $new = Mockery::mock(Connection::class);

    $old->shouldNotReceive('disconnect');

    $handler->join(
        $old, $host['code'], $host['token'], fn () => null,
    );

    expect(fn () => $handler->join(
        $new,
        $host['code'],
        $host['token'],
        function (): void {
            throw new RuntimeException('Send failed.');
        },
    ))->toThrow(RuntimeException::class, 'Send failed.');

    expect($handler->isCurrent($old))->toBeTrue()
        ->and($handler->isBound($new))->toBeFalse();
});

it('keeps a replacement connection when the old socket disconnects', function (): void {
    $host = app(ConversationStore::class)->create();
    $handler = conversationHandler();

    $old = Mockery::mock(Connection::class);
    $new = Mockery::mock(Connection::class);

    $old->shouldReceive('isConnected')->once()->andReturn(true);
    $old->shouldReceive('disconnect')->once();

    $send = fn () => null;

    $handler->join($old, $host['code'], $host['token'], $send);
    $handler->join($new, $host['code'], $host['token'], $send);

    expect($handler->isCurrent($old))->toBeFalse()
        ->and($handler->isCurrent($new))->toBeTrue();

    $handler->disconnect($old, $send);

    expect($handler->isCurrent($new))->toBeTrue()
        ->and($handler->isBound($old))->toBeFalse();
});

it('notifies a peer when the current participant disconnects', function (): void {
    $store = app(ConversationStore::class);
    $host = $store->create();
    $guest = $store->join($host['code']);

    $handler = conversationHandler();

    $a = Mockery::mock(Connection::class);
    $b = Mockery::mock(Connection::class);

    $a->shouldReceive('isConnected')->andReturn(true);
    $b->shouldReceive('isConnected')->andReturn(true);

    $events = [];

    $send = function ($to, $payload) use (&$events): void {
        $events[] = [$to, $payload];
    };

    $handler->join($a, $host['code'], $host['token'], $send);
    $handler->join($b, $guest['code'], $guest['token'], $send);

    $handler->disconnect($b, $send);

    expect($handler->isCurrent($b))->toBeFalse()
        ->and($events[array_key_last($events)][0])->toBe($a)
        ->and($events[array_key_last($events)][1]['type'])
        ->toBe('conversation_peer_left');
});
