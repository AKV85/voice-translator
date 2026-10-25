<?php

namespace App\Services\Conversation;

use App\Enums\Language;
use LogicException;
use WebSocket\Connection;

final class ConversationPendingWorkerRegistry
{
    /**
     * @var array<string, array{
     *     owner: Connection,
     *     peer: Connection,
     *     source_text: string,
     *     source_language: Language,
     *     correlation: array<string, mixed>,
     *     target_language: Language
     * }>
     */
    private array $jobs = [];

    /** @param  array<string, mixed>  $correlation */
    public function add(
        string $turnId,
        Connection $owner,
        Connection $peer,
        string $sourceText,
        Language $sourceLanguage,
        Language $targetLanguage,
        array $correlation = [],
    ): void {
        if ($turnId === '' || isset($this->jobs[$turnId])) {
            throw new LogicException(
                'Invalid or duplicate pending conversation worker.',
            );
        }

        foreach ($this->jobs as $job) {
            if ($job['owner'] === $owner) {
                throw new LogicException(
                    'Conversation owner already has a pending worker.',
                );
            }
        }

        $this->jobs[$turnId] = [
            'owner' => $owner,
            'peer' => $peer,
            'source_text' => $sourceText,
            'source_language' => $sourceLanguage,
            'target_language' => $targetLanguage,
            'correlation' => $correlation,
        ];
    }

    /**
     * Remove every pending job involving this connection.
     *
     * @return list<string>
     */
    public function removeFor(Connection $connection): array
    {
        $turnIds = $this->turnIdsFor($connection);

        foreach ($turnIds as $turnId) {
            unset($this->jobs[$turnId]);
        }

        return $turnIds;
    }

    public function hasOwner(Connection $owner): bool
    {
        foreach ($this->jobs as $job) {
            if ($job['owner'] === $owner) {
                return true;
            }
        }

        return false;
    }

    public function remove(string $turnId): void
    {
        unset($this->jobs[$turnId]);
    }

    /**
     * @return array<string, array{
     *     owner: Connection,
     *     peer: Connection,
     *     source_text: string,
     *     source_language: Language,
     *     correlation: array<string, mixed>,
     *     target_language: Language
     * }>
     */
    public function all(): array
    {
        return $this->jobs;
    }

    /**
     * @return list<string>
     */
    public function turnIdsFor(Connection $connection): array
    {
        $turnIds = [];

        foreach ($this->jobs as $turnId => $job) {
            if (
                $job['owner'] === $connection
                || $job['peer'] === $connection
            ) {
                $turnIds[] = $turnId;
            }
        }

        return $turnIds;
    }
}
