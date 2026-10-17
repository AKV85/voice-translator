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

// Give the WebSocket server time to handle other messages.
usleep(300000);

$translated = sprintf(
    'FAKE-TRANSLATION-%s-%s:%s',
    $input['source_language'],
    $input['target_language'],
    $input['source_text'],
);

$audio = pack('v*', 0, 1200, 2400, 1200, 0);

if (($input['stream'] ?? false) === true) {
    $emit = static function (array $event) use ($input): void {
        $event['turn_id'] = $input['turn_id'];

        fwrite(
            STDOUT,
            json_encode($event, JSON_THROW_ON_ERROR)."\n",
        );

        fflush(STDOUT);
    };

    $emit([
        'type' => 'translation',
        'translated_text' => $translated,
    ]);

    $emit([
        'type' => 'tts_started',
        'mime_type' => 'audio/pcm',
    ]);

    $emit([
        'type' => 'audio_chunk',
        'audio_base64' => base64_encode(substr($audio, 0, 4)),
    ]);

    // The first audio chunk must be delivered before the second.
    usleep(700000);

    $emit([
        'type' => 'audio_chunk',
        'audio_base64' => base64_encode(substr($audio, 4)),
    ]);

    // Worker remains alive after audio has been emitted.
    usleep(500000);

    $emit([
        'type' => 'completed',
        'audio_bytes' => strlen($audio),
    ]);

    exit(0);
}

// Preserve the original one-response protocol.
echo json_encode([
    'turn_id' => $input['turn_id'],
    'status' => 'completed',
    'translated_text' => $translated,
    'audio_base64' => base64_encode($audio),
    'mime_type' => 'audio/pcm',
], JSON_THROW_ON_ERROR);
