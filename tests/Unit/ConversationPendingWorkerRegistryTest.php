<?php

use App\Enums\Language;
use App\Services\Conversation\ConversationPendingWorkerRegistry;
use WebSocket\Connection;

it('tracks pending workers by their original connections', function (): void {
    $registry = new ConversationPendingWorkerRegistry;

    $owner = Mockery::mock(Connection::class);
    $peer = Mockery::mock(Connection::class);
    $replacementPeer = Mockery::mock(Connection::class);

    $registry->add(
        turnId: 'turn-1',
        owner: $owner,
        peer: $peer,
        sourceText: 'Привет!',
        sourceLanguage: Language::from('ru'),
        targetLanguage: Language::from('en'),
    );

    expect($registry->turnIdsFor($owner))->toBe(['turn-1'])
        ->and($registry->turnIdsFor($peer))->toBe(['turn-1'])
        ->and($registry->turnIdsFor($replacementPeer))->toBeEmpty()
        ->and($registry->all()['turn-1']['peer'])->toBe($peer);

    $registry->remove('turn-1');

    expect($registry->all())->toBeEmpty();
});

it('rejects concurrent pending workers for the same owner', function (): void {
    $registry = new ConversationPendingWorkerRegistry;

    $owner = Mockery::mock(Connection::class);
    $peer = Mockery::mock(Connection::class);

    $registry->add(
        'turn-1',
        $owner,
        $peer,
        'Привет!',
        Language::from('ru'),
        Language::from('en'),
    );

    expect(fn () => $registry->add(
        'turn-2',
        $owner,
        $peer,
        'Ещё одна реплика',
        Language::from('ru'),
        Language::from('en'),
    ))->toThrow(
        LogicException::class,
        'Conversation owner already has a pending worker.',
    );
});

it('removes pending jobs when either participant disconnects', function (): void {
    $registry = new ConversationPendingWorkerRegistry;

    $host = Mockery::mock(Connection::class);
    $guest = Mockery::mock(Connection::class);
    $otherHost = Mockery::mock(Connection::class);
    $otherGuest = Mockery::mock(Connection::class);

    $registry->add(
        'turn-1',
        $host,
        $guest,
        'Привет!',
        Language::from('ru'),
        Language::from('en'),
    );

    $registry->add(
        'turn-2',
        $otherHost,
        $otherGuest,
        'Hello!',
        Language::from('en'),
        Language::from('ru'),
    );

    expect($registry->removeFor($guest))->toBe(['turn-1'])
        ->and(array_keys($registry->all()))->toBe(['turn-2'])
        ->and($registry->removeFor($host))->toBeEmpty()
        ->and($registry->removeFor($otherHost))->toBe(['turn-2'])
        ->and($registry->all())->toBeEmpty();
});

it('identifies owners with pending workers', function (): void {
    $registry = new ConversationPendingWorkerRegistry;

    $owner = Mockery::mock(Connection::class);
    $peer = Mockery::mock(Connection::class);

    expect($registry->hasOwner($owner))->toBeFalse();

    $registry->add(
        'turn-1',
        $owner,
        $peer,
        'Привет!',
        Language::from('ru'),
        Language::from('en'),
    );

    expect($registry->hasOwner($owner))->toBeTrue();

    $registry->remove('turn-1');

    expect($registry->hasOwner($owner))->toBeFalse();
});
