<?php

namespace App\Services\Conversation;

final class ConversationSegmentSequence
{
    private readonly string $turnId;

    private int $index = 0;

    public function __construct()
    {
        $this->turnId = bin2hex(random_bytes(16));
    }

    public function turnId(): string
    {
        return $this->turnId;
    }

    /** @return array{conversation_turn_id: string, segment_id: string, segment_index: int} */
    public function next(): array
    {
        return [
            'conversation_turn_id' => $this->turnId,
            'segment_id' => bin2hex(random_bytes(16)),
            'segment_index' => ++$this->index,
        ];
    }
}
