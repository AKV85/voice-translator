<?php

namespace App\Services\Conversation;

use InvalidArgumentException;
use WebSocket\Connection;

final class ConversationConnectionRegistry
{
    /**
     * @var array<string, array<string, Connection>>
     */
    private array $connections = [];

    public function attach(
        string $code,
        string $role,
        Connection $connection,
    ): void {
        if (! in_array($role, ['host', 'guest'], true)) {
            throw new InvalidArgumentException(
                'Unsupported conversation participant role.',
            );
        }

        $this->connections[$code][$role] = $connection;
    }

    public function peer(
        string $code,
        string $role,
    ): ?Connection {
        $peerRole = match ($role) {
            'host' => 'guest',
            'guest' => 'host',
            default => throw new InvalidArgumentException(
                'Unsupported conversation participant role.',
            ),
        };

        return $this->connections[$code][$peerRole] ?? null;
    }

    public function isCurrent(
        string $code,
        string $role,
        Connection $connection,
    ): bool {
        return (
            $this->connections[$code][$role] ?? null
        ) === $connection;
    }

    /**
     * Remove only the exact connection that disconnected.
     */
    public function detach(
        string $code,
        string $role,
        Connection $connection,
    ): bool {
        if (! $this->isCurrent($code, $role, $connection)) {
            return false;
        }

        unset($this->connections[$code][$role]);

        if ($this->connections[$code] === []) {
            unset($this->connections[$code]);
        }

        return true;
    }
}
