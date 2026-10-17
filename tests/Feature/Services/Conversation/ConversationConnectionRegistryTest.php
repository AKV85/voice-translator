<?php

use App\Services\Conversation\ConversationConnectionRegistry;
use WebSocket\Connection;

it('finds the other conversation participant', function (): void {
    $registry = new ConversationConnectionRegistry;

    $host = Mockery::mock(Connection::class);
    $guest = Mockery::mock(Connection::class);

    $registry->attach('ABCDEFGH', 'host', $host);
    $registry->attach('ABCDEFGH', 'guest', $guest);

    expect($registry->peer('ABCDEFGH', 'host'))->toBe($guest)
        ->and($registry->peer('ABCDEFGH', 'guest'))->toBe($host);
});

it('does not mix connections from different rooms', function (): void {
    $registry = new ConversationConnectionRegistry;

    $host = Mockery::mock(Connection::class);
    $guest = Mockery::mock(Connection::class);

    $registry->attach('ROOM0001', 'host', $host);
    $registry->attach('ROOM0002', 'guest', $guest);

    expect($registry->peer('ROOM0001', 'host'))->toBeNull()
        ->and($registry->peer('ROOM0002', 'guest'))->toBeNull();
});

it('preserves a replacement connection on an old disconnect', function (): void {
    $registry = new ConversationConnectionRegistry;

    $old = Mockery::mock(Connection::class);
    $new = Mockery::mock(Connection::class);

    $registry->attach('ABCDEFGH', 'host', $old);
    $registry->attach('ABCDEFGH', 'host', $new);

    expect($registry->detach('ABCDEFGH', 'host', $old))->toBeFalse()
        ->and($registry->isCurrent('ABCDEFGH', 'host', $new))->toBeTrue()
        ->and($registry->detach('ABCDEFGH', 'host', $new))->toBeTrue()
        ->and($registry->isCurrent('ABCDEFGH', 'host', $new))->toBeFalse();
});

it('rejects unsupported participant roles', function (): void {
    $registry = new ConversationConnectionRegistry;

    $connection = Mockery::mock(Connection::class);

    expect(
        fn () => $registry->attach(
            'ABCDEFGH',
            'observer',
            $connection,
        ),
    )->toThrow(InvalidArgumentException::class);
});
