<?php

use App\Services\Conversation\ConversationConnectionRegistry;
use App\Services\Conversation\ConversationStore;
use App\Services\Conversation\ConversationTurnManager;
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

it('releases the old host turn after successful reconnection', function (): void {
    $host = app(ConversationStore::class)->create();
    $turns = new ConversationTurnManager;

    $handler = new ConversationWebSocketHandler(
        app(ConversationStore::class),
        new ConversationConnectionRegistry,
        $turns,
    );

    $old = Mockery::mock(Connection::class);
    $new = Mockery::mock(Connection::class);

    $old->shouldReceive('isConnected')->once()->andReturn(true);
    $old->shouldReceive('disconnect')->once();

    $handler->join($old, $host['code'], $host['token'], fn () => null);

    expect($turns->acquire($host['code'], 'host', $old))->toBeTrue();

    $handler->join($new, $host['code'], $host['token'], fn () => null);

    expect($handler->isCurrent($new))->toBeTrue()
        ->and($turns->isOwner($host['code'], $old))->toBeFalse()
        ->and($turns->acquire($host['code'], 'host', $new))->toBeTrue();
});

it('preserves the active turn when reconnection acknowledgement fails', function (): void {
    $host = app(ConversationStore::class)->create();
    $turns = new ConversationTurnManager;

    $handler = new ConversationWebSocketHandler(
        app(ConversationStore::class),
        new ConversationConnectionRegistry,
        $turns,
    );

    $old = Mockery::mock(Connection::class);
    $new = Mockery::mock(Connection::class);

    $old->shouldNotReceive('disconnect');

    $handler->join($old, $host['code'], $host['token'], fn () => null);

    expect($turns->acquire($host['code'], 'host', $old))->toBeTrue();

    expect(fn () => $handler->join(
        $new,
        $host['code'],
        $host['token'],
        function (): void {
            throw new RuntimeException('Acknowledgement failed.');
        },
    ))->toThrow(RuntimeException::class, 'Acknowledgement failed.');

    expect($handler->isCurrent($old))->toBeTrue()
        ->and($turns->isOwner($host['code'], $old))->toBeTrue()
        ->and($turns->acquire($host['code'], 'host', $new))->toBeFalse();
});

it('does not release a replacement turn when the old connection disconnects', function (): void {
    $host = app(ConversationStore::class)->create();
    $turns = new ConversationTurnManager;

    $handler = new ConversationWebSocketHandler(
        app(ConversationStore::class),
        new ConversationConnectionRegistry,
        $turns,
    );

    $old = Mockery::mock(Connection::class);
    $new = Mockery::mock(Connection::class);

    $old->shouldReceive('isConnected')->once()->andReturn(true);
    $old->shouldReceive('disconnect')->once();

    $send = fn () => null;

    $handler->join($old, $host['code'], $host['token'], $send);

    expect($turns->acquire($host['code'], 'host', $old))->toBeTrue();

    $handler->join($new, $host['code'], $host['token'], $send);

    expect($turns->acquire($host['code'], 'host', $new))->toBeTrue();

    $handler->disconnect($old, $send);

    expect($handler->isCurrent($new))->toBeTrue()
        ->and($turns->isOwner($host['code'], $new))->toBeTrue()
        ->and($turns->release($host['code'], $old))->toBeFalse();
});

it('notifies the peer about turn start and stop', function (): void {
    $store = app(ConversationStore::class);
    $host = $store->create();
    $guest = $store->join($host['code']);

    $turns = new ConversationTurnManager;
    $handler = new ConversationWebSocketHandler(
        $store,
        new ConversationConnectionRegistry,
        $turns,
    );

    $a = Mockery::mock(Connection::class);
    $b = Mockery::mock(Connection::class);

    $a->shouldReceive('isConnected')->andReturn(true);
    $b->shouldReceive('isConnected')->andReturn(true);

    $events = [];

    $send = function ($to, $payload) use (&$events): void {
        $events[] = [$to, $payload['type']];
    };

    $handler->join($a, $host['code'], $host['token'], $send);
    $handler->join($b, $guest['code'], $guest['token'], $send);

    $events = [];

    $handler->startTurn($a, $send);
    $handler->startTurn($b, $send);
    $handler->stopTurn($a, $send);
    $handler->startTurn($b, $send);
    $handler->stopTurn($b, $send);

    expect(array_map(
        fn (array $event): string => $event[1],
        $events,
    ))->toBe([
        'conversation_turn_started',
        'conversation_peer_started',
        'conversation_turn_busy',
        'conversation_turn_stopped',
        'conversation_peer_stopped',
        'conversation_turn_started',
        'conversation_peer_started',
        'conversation_turn_stopped',
        'conversation_peer_stopped',
    ]);

    expect($events[1][0])->toBe($b)
        ->and($events[2][0])->toBe($b)
        ->and($events[4][0])->toBe($b)
        ->and($events[6][0])->toBe($a)
        ->and($turns->isOwner($host['code'], $a))->toBeFalse()
        ->and($turns->isOwner($host['code'], $b))->toBeFalse();
});

it('preserves the turn when stop acknowledgement fails', function (): void {
    $store = app(ConversationStore::class);
    $host = $store->create();

    $turns = new ConversationTurnManager;
    $handler = new ConversationWebSocketHandler(
        $store,
        new ConversationConnectionRegistry,
        $turns,
    );

    $connection = Mockery::mock(Connection::class);

    $handler->join(
        $connection,
        $host['code'],
        $host['token'],
        fn () => null,
    );

    $handler->startTurn($connection, fn () => null);

    expect($turns->isOwner($host['code'], $connection))
        ->toBeTrue();

    expect(fn () => $handler->stopTurn(
        $connection,
        function (): void {
            throw new RuntimeException('Acknowledgement failed.');
        },
    ))->toThrow(RuntimeException::class, 'Acknowledgement failed.');

    expect($turns->isOwner($host['code'], $connection))
        ->toBeTrue();

    $handler->stopTurn($connection, fn () => null);

    expect($turns->isOwner($host['code'], $connection))
        ->toBeFalse();
});

it('returns the authenticated conversation context', function (): void {
    $store = app(ConversationStore::class);
    $host = $store->create();
    $guest = $store->join($host['code']);

    $handler = conversationHandler();

    $a = Mockery::mock(Connection::class);
    $b = Mockery::mock(Connection::class);

    $a->shouldReceive('isConnected')->andReturn(true);

    $handler->join($a, $host['code'], $host['token'], fn () => null);
    $handler->join($b, $guest['code'], $guest['token'], fn () => null);

    expect($handler->currentContext($a))->toBe([
        'code' => $host['code'],
        'role' => 'host',
        'language' => 'ru',
    ]);

    expect($handler->currentContext($b))->toBe([
        'code' => $host['code'],
        'role' => 'guest',
        'language' => 'en',
    ]);
});

it('rejects conversation context from a replaced connection', function (): void {
    $store = app(ConversationStore::class);
    $host = $store->create();

    $handler = conversationHandler();

    $old = Mockery::mock(Connection::class);
    $new = Mockery::mock(Connection::class);

    $old->shouldReceive('isConnected')->once()->andReturn(true);
    $old->shouldReceive('disconnect')->once();

    $handler->join($old, $host['code'], $host['token'], fn () => null);
    $handler->join($new, $host['code'], $host['token'], fn () => null);

    expect(fn () => $handler->currentContext($old))
        ->toThrow(LogicException::class, 'Stale');

    expect($handler->currentContext($new)['role'])->toBe('host');
});

it('uses the authenticated participant language rather than inferring it from the role', function (): void {
    $store = app(ConversationStore::class);
    $host = $store->create();

    $cache = Cache::store('array');
    $key = 'conversation:'.$host['code'];

    $conversation = $cache->get($key);

    expect($conversation)->toBeArray();

    $conversation['host']['language'] = 'en';

    $cache->put($key, $conversation, 1800);

    $handler = conversationHandler();
    $connection = Mockery::mock(Connection::class);

    $handler->join(
        $connection,
        $host['code'],
        $host['token'],
        fn () => null,
    );

    expect($handler->currentContext($connection)['language'])
        ->toBe('en');
});

it('releases the turn when preparation fails', function (): void {
    $store = app(ConversationStore::class);
    $host = $store->create();

    $turns = new ConversationTurnManager;

    $handler = new ConversationWebSocketHandler(
        $store,
        new ConversationConnectionRegistry,
        $turns,
    );

    $connection = Mockery::mock(Connection::class);
    $events = [];

    $send = function ($to, array $payload) use (&$events): void {
        $events[] = $payload['type'];
    };

    $handler->join(
        $connection,
        $host['code'],
        $host['token'],
        $send,
    );

    $events = [];

    expect(fn () => $handler->startTurn(
        $connection,
        $send,
        static function (): void {
            throw new RuntimeException('STT startup failed.');
        },
    ))->toThrow(RuntimeException::class, 'STT startup failed.');

    expect($turns->isOwner($host['code'], $connection))->toBeFalse()
        ->and($events)->toBeEmpty();

    $handler->startTurn($connection, $send);

    expect($events)->toBe(['conversation_turn_started']);
});

it('does not prepare an already occupied turn', function (): void {
    $store = app(ConversationStore::class);
    $host = $store->create();
    $guest = $store->join($host['code']);

    $turns = new ConversationTurnManager;

    $handler = new ConversationWebSocketHandler(
        $store,
        new ConversationConnectionRegistry,
        $turns,
    );

    $a = Mockery::mock(Connection::class);
    $b = Mockery::mock(Connection::class);

    $a->shouldReceive('isConnected')->andReturn(true);
    $b->shouldReceive('isConnected')->andReturn(true);

    $send = fn ($to, array $payload) => null;

    $handler->join($a, $host['code'], $host['token'], $send);
    $handler->join($b, $guest['code'], $guest['token'], $send);

    $handler->startTurn($a, $send);

    $preparations = 0;
    $events = [];

    $handler->startTurn(
        $b,
        function ($to, array $payload) use (&$events): void {
            $events[] = $payload['type'];
        },
        function () use (&$preparations): void {
            $preparations++;
        },
    );

    expect($preparations)->toBe(0)
        ->and($events)->toBe(['conversation_turn_busy'])
        ->and($turns->isOwner($host['code'], $a))->toBeTrue();
});
