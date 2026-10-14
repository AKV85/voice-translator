<?php

namespace App\Services\Conversation;

use Closure;
use InvalidArgumentException;
use LogicException;
use Throwable;
use WebSocket\Connection;

final class ConversationWebSocketHandler
{
    /** @var array<int, array{code: string, role: string, language: string}> */
    private array $participants = [];

    public function __construct(
        private readonly ConversationStore $store,
        private readonly ConversationConnectionRegistry $registry,
        private readonly ?ConversationTurnManager $turnManager = null,
    ) {}

    /**
     * @param  Closure(Connection, array): void  $send
     */
    public function join(
        Connection $connection,
        string $code,
        string $token,
        Closure $send,
    ): void {
        if (! config('conversation.enabled', false)) {
            throw new LogicException('Conversation mode is disabled.');
        }

        $key = spl_object_id($connection);

        if (isset($this->participants[$key])) {
            throw new LogicException(
                'Connection already belongs to a conversation.',
            );
        }

        $participant = $this->store->authenticate($code, $token);

        if ($participant === null) {
            throw new InvalidArgumentException(
                'Invalid or expired conversation credentials.',
            );
        }

        $code = $participant['code'];
        $role = $participant['role'];

        $oppositeRole = $role === 'host' ? 'guest' : 'host';

        $previous = $this->registry->peer($code, $oppositeRole);
        $peer = $this->registry->peer($code, $role);

        $peerConnected = $peer !== null && $peer->isConnected();

        // Send acknowledgement before changing registry state.
        // If sending fails, the previous connection remains intact.
        $send($connection, [
            'type' => 'conversation_joined',
            'code' => $code,
            'role' => $role,
            'language' => $participant['language'],
            'peer_connected' => $peerConnected,
        ]);

        $this->registry->attach($code, $role, $connection);

        if ($previous !== null && $previous !== $connection) {
            $this->turnManager?->release($code, $previous);
        }

        $this->participants[$key] = [
            'code' => $code,
            'role' => $role,
            'language' => $participant['language'],
        ];

        if (
            $previous !== null
            && $previous !== $connection
            && $previous->isConnected()
        ) {
            try {
                $previous->disconnect();
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($peerConnected) {
            try {
                $send($peer, [
                    'type' => 'conversation_peer_joined',
                ]);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * @param  Closure(Connection, array): void  $send
     */
    public function startTurn(
        Connection $connection,
        Closure $send,
        ?Closure $prepare = null,
    ): void {
        $participant = $this->currentParticipant($connection);
        $code = $participant['code'];
        $role = $participant['role'];

        $manager = $this->turnManager
            ?? throw new LogicException(
                'Conversation turn manager unavailable.',
            );

        if (! $manager->acquire($code, $role, $connection)) {
            $send($connection, [
                'type' => 'conversation_turn_busy',
            ]);

            return;
        }

        try {
            $prepare?->__invoke();

            $send($connection, [
                'type' => 'conversation_turn_started',
            ]);
        } catch (Throwable $exception) {
            $manager->release($code, $connection);

            throw $exception;
        }

        $peer = $this->registry->peer($code, $role);

        if ($peer !== null && $peer->isConnected()) {
            try {
                $send($peer, [
                    'type' => 'conversation_peer_started',
                ]);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * @param  Closure(Connection, array): void  $send
     */
    public function stopTurn(
        Connection $connection,
        Closure $send,
    ): void {
        $participant = $this->currentParticipant($connection);
        $code = $participant['code'];
        $role = $participant['role'];

        $manager = $this->turnManager
            ?? throw new LogicException(
                'Conversation turn manager unavailable.',
            );

        if (! $manager->isOwner($code, $connection)) {
            throw new LogicException(
                'Connection does not own the conversation turn.',
            );
        }

        $send($connection, [
            'type' => 'conversation_turn_stopped',
        ]);

        $manager->release($code, $connection);

        $peer = $this->registry->peer($code, $role);

        if ($peer !== null && $peer->isConnected()) {
            try {
                $send($peer, [
                    'type' => 'conversation_peer_stopped',
                ]);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * @return array{code: string, role: string, language: string}
     */
    private function currentParticipant(Connection $connection): array
    {
        $participant = $this->participants[spl_object_id($connection)]
            ?? null;

        if (
            $participant === null
            || ! $this->registry->isCurrent(
                $participant['code'],
                $participant['role'],
                $connection,
            )
        ) {
            throw new LogicException(
                'Stale or unauthenticated conversation connection.',
            );
        }

        return $participant;
    }

    /**
     * @return array{code: string, role: string, language: string}
     */
    public function currentContext(Connection $connection): array
    {
        $participant = $this->currentParticipant($connection);

        return [
            'code' => $participant['code'],
            'role' => $participant['role'],
            'language' => $participant['language'],
        ];
    }

    /**
     * Release a failed turn without acknowledging successful completion.
     *
     * @param  Closure(Connection, array): void  $send
     */
    public function abortTurn(
        Connection $connection,
        Closure $send,
    ): void {
        $participant = $this->currentParticipant($connection);

        $manager = $this->turnManager
            ?? throw new LogicException(
                'Conversation turn manager unavailable.',
            );

        if (! $manager->release($participant['code'], $connection)) {
            return;
        }

        $peer = $this->registry->peer(
            $participant['code'],
            $participant['role'],
        );

        if ($peer !== null && $peer->isConnected()) {
            try {
                $send($peer, [
                    'type' => 'conversation_peer_stopped',
                ]);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    public function currentPeer(Connection $connection): ?Connection
    {
        $participant = $this->currentParticipant($connection);

        $peer = $this->registry->peer(
            $participant['code'],
            $participant['role'],
        );

        if ($peer === null || ! $peer->isConnected()) {
            return null;
        }

        return $peer;
    }

    public function ownsTurn(Connection $connection): bool
    {
        $participant = $this->currentParticipant($connection);

        return $this->turnManager?->isOwner(
            $participant['code'],
            $connection,
        ) ?? false;
    }

    public function isBound(Connection $connection): bool
    {
        return isset($this->participants[spl_object_id($connection)]);
    }

    public function isCurrent(Connection $connection): bool
    {
        $participant = $this->participants[spl_object_id($connection)]
            ?? null;

        return $participant !== null
            && $this->registry->isCurrent(
                $participant['code'],
                $participant['role'],
                $connection,
            );
    }

    /**
     * @param  Closure(Connection, array): void  $send
     */
    public function disconnect(
        Connection $connection,
        Closure $send,
    ): void {
        $key = spl_object_id($connection);
        $participant = $this->participants[$key] ?? null;

        unset($this->participants[$key]);

        if ($participant === null) {
            return;
        }

        $code = $participant['code'];
        $role = $participant['role'];

        $this->turnManager?->release($code, $connection);

        if (! $this->registry->detach($code, $role, $connection)) {
            return;
        }

        $peer = $this->registry->peer($code, $role);

        if ($peer !== null && $peer->isConnected()) {
            try {
                $send($peer, [
                    'type' => 'conversation_peer_left',
                ]);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
