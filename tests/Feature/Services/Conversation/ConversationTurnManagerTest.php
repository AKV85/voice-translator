<?php

use App\Services\Conversation\ConversationTurnManager;
use WebSocket\Connection;

it('allows only one active speaker per room', function (): void {
    $manager = new ConversationTurnManager;

    $host = Mockery::mock(Connection::class);
    $guest = Mockery::mock(Connection::class);

    expect($manager->acquire('ROOM1234', 'host', $host))->toBeTrue()
        ->and($manager->acquire('ROOM1234', 'guest', $guest))->toBeFalse()
        ->and($manager->acquire('ROOM1234', 'host', $host))->toBeFalse()
        ->and($manager->isOwner('ROOM1234', $host))->toBeTrue()
        ->and($manager->isOwner('ROOM1234', $guest))->toBeFalse();
});

it('allows the next participant after a turn is released', function (): void {
    $manager = new ConversationTurnManager;

    $host = Mockery::mock(Connection::class);
    $guest = Mockery::mock(Connection::class);

    expect($manager->acquire('ROOM1234', 'host', $host))->toBeTrue()
        ->and($manager->release('ROOM1234', $host))->toBeTrue()
        ->and($manager->acquire('ROOM1234', 'guest', $guest))->toBeTrue();
});

it('does not allow another connection to release the active turn', function (): void {
    $manager = new ConversationTurnManager;

    $old = Mockery::mock(Connection::class);
    $current = Mockery::mock(Connection::class);

    expect($manager->acquire('ROOM1234', 'host', $old))->toBeTrue()
        ->and($manager->release('ROOM1234', $current))->toBeFalse()
        ->and($manager->isOwner('ROOM1234', $old))->toBeTrue();
});

it('keeps turns in different rooms independent', function (): void {
    $manager = new ConversationTurnManager;

    $host = Mockery::mock(Connection::class);
    $guest = Mockery::mock(Connection::class);

    expect($manager->acquire('ROOM1234', 'host', $host))->toBeTrue()
        ->and($manager->acquire('ROOM5678', 'guest', $guest))->toBeTrue();
});

it('allows a new connection to speak after the previous owner releases the turn', function (): void {
    $manager = new ConversationTurnManager;

    $old = Mockery::mock(Connection::class);
    $new = Mockery::mock(Connection::class);

    expect($manager->acquire('ROOM1234', 'host', $old))->toBeTrue()
        ->and($manager->release('ROOM1234', $old))->toBeTrue()
        ->and($manager->acquire('ROOM1234', 'host', $new))->toBeTrue()
        ->and($manager->isOwner('ROOM1234', $old))->toBeFalse();
});
