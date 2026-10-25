<?php

use App\Services\Conversation\ConversationConnectionRegistry;
use App\Services\Conversation\ConversationStore;
use App\Services\Conversation\ConversationTiming;
use App\Services\Conversation\ConversationTurnManager;
use App\Services\Conversation\ConversationWebSocketHandler;
use WebSocket\Connection;

beforeEach(function (): void {
    config(['conversation.enabled' => true, 'cache.default' => 'array']);
});

it('replaces either role while a late disconnect cannot release the new connection or its turn', function (string $role): void {
    $store = app(ConversationStore::class);
    $host = $store->create();
    $guest = $store->join($host['code']);
    $credentials = $role === 'host' ? $host : $guest;
    $turns = new ConversationTurnManager;
    $handler = new ConversationWebSocketHandler($store, new ConversationConnectionRegistry, $turns);
    $old = Mockery::mock(Connection::class);
    $old->shouldReceive('isConnected')->andReturn(true);
    $old->shouldReceive('disconnect')->once();
    $new = Mockery::mock(Connection::class);
    $events = [];
    $send = function (Connection $to, array $event) use (&$events): void {
        $events[] = $event;
    };
    $handler->join($old, $credentials['code'], $credentials['token'], $send);
    $handler->startTurn($old, $send);
    $handler->join($new, $credentials['code'], $credentials['token'], $send);
    $handler->startTurn($new, $send);
    $handler->disconnect($old, $send);
    expect($handler->isCurrent($new))->toBeTrue()
        ->and($handler->ownsTurn($new))->toBeTrue()
        ->and(array_column($events, 'type'))->toContain('conversation_replaced')
        ->and(json_encode($events))->not->toContain($credentials['token']);
    expect(fn () => $handler->startTurn($old, $send))->toThrow(LogicException::class);
    expect(fn () => $store->join($host['code']))->toThrow(RuntimeException::class, 'Conversation is full.');
})->with(['host', 'guest']);

it('rejects invalid and expired credentials without replacing an authenticated participant', function (): void {
    $store = app(ConversationStore::class);
    $host = $store->create();
    $handler = new ConversationWebSocketHandler($store, new ConversationConnectionRegistry, new ConversationTurnManager);
    $connection = Mockery::mock(Connection::class);
    $handler->join($connection, $host['code'], $host['token'], fn () => null);
    $intruder = Mockery::mock(Connection::class);
    expect(fn () => $handler->join($intruder, $host['code'], str_repeat('x', 64), fn () => null))->toThrow(InvalidArgumentException::class)
        ->and($handler->isCurrent($connection))->toBeTrue();
    $this->travel((int) config('conversation.ttl_seconds') + 1)->seconds();
    expect(fn () => $handler->join($intruder, $host['code'], $host['token'], fn () => null))->toThrow(InvalidArgumentException::class);
});

it('blocks developer lab HTTP and conversation WebSocket joins in production even with flags enabled', function (): void {
    config(['live_pipeline.lab_enabled' => true, 'conversation.latency_debug_enabled' => true]);
    $store = app(ConversationStore::class);
    $host = $store->create();
    $this->app->instance('env', 'production');
    $this->get('/conversation')->assertNotFound();
    $this->withSession(['_token' => 'test-csrf'])->postJson('/conversation/create', [], ['X-CSRF-TOKEN' => 'test-csrf'])->assertNotFound();
    $handler = new ConversationWebSocketHandler($store, new ConversationConnectionRegistry);
    expect(fn () => $handler->join(Mockery::mock(Connection::class), $host['code'], $host['token'], fn () => null))->toThrow(LogicException::class)
        ->and(ConversationTiming::enabled())->toBeFalse();
});
