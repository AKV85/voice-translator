<?php

declare(strict_types=1);

$input = json_decode(
    stream_get_contents(STDIN),
    true,
    flags: JSON_THROW_ON_ERROR,
);

if (
    ! is_array($input)
    || ! is_string($input['turn_id'] ?? null)
    || ! is_string($input['source_text'] ?? null)
    || ! is_string($input['source_language'] ?? null)
    || ! is_string($input['target_language'] ?? null)
) {
    fwrite(STDERR, "Invalid worker input.\n");

    exit(1);
}

// Give the WebSocket server time to handle another message
// while translation is running.
usleep(1500000);

$audio = pack('v*', 0, 1200, 2400, 1200, 0);

echo json_encode([
    'turn_id' => $input['turn_id'],
    'status' => 'completed',
    'translated_text' => sprintf(
        'FAKE-TRANSLATION-%s-%s:%s',
        $input['source_language'],
        $input['target_language'],
        $input['source_text'],
    ),
    'audio_base64' => base64_encode($audio),
    'mime_type' => 'audio/pcm',
], JSON_THROW_ON_ERROR);
