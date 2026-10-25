<?php

namespace App\Services\Conversation;

final class ConversationTiming
{
    public const METRICS = [
        'startup_ms', 'queue_wait_ms', 'stt_completion_ms',
        'translation_ms', 'translation_to_first_pcm_ms', 'total_ms',
        'tts_request_ms', 'tts_request_to_headers_ms', 'tts_request_to_first_audio_ms',
        'first_pcm_emitted_ms', 'first_audio_to_pcm_emit_ms',
        'final_to_first_pcm_send_ms', 'stop_to_first_pcm_send_ms', 'socket_write_ms',
    ];

    public static function enabled(): bool
    {
        return app()->environment(['local', 'testing'])
            && (bool) config('conversation.latency_debug_enabled', false);
    }

    /** @param array<string, mixed> $values
     * @return array<string, int|float|null>
     */
    public static function durations(array $values): array
    {
        return array_filter(array_intersect_key($values, array_flip(self::METRICS)),
            static fn ($value): bool => $value === null
                || ((is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0));
    }
}
