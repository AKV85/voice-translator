<?php

namespace App\Services\Conversation;

use WebSocket\Connection;

class ConversationTurnManager
{
    /**
     * @var array<string, array{role: string, connection: Connection}>
     */
    private array $turns = [];

    public function acquire(
        string $code,
        string $role,
        Connection $connection,
    ): bool {
        if (isset($this->turns[$code])) {
            return false;
        }

        $this->turns[$code] = [
            'role' => $role,
            'connection' => $connection,
        ];

        return true;
    }

    public function isOwner(
        string $code,
        Connection $connection,
    ): bool {
        return isset($this->turns[$code])
            && $this->turns[$code]['connection'] === $connection;
    }

    public function release(
        string $code,
        Connection $connection,
    ): bool {
        if (! $this->isOwner($code, $connection)) {
            return false;
        }

        unset($this->turns[$code]);

        return true;
    }
}
