<?php

declare(strict_types=1);

use App\Enums\Language;
use App\Services\Conversation\ConversationPipelineProcessor;
use Tests\Fakes\FakeStreamingTextToSpeechProvider;
use Tests\Fakes\FakeTranslationProvider;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$input = stream_get_contents(STDIN);

if ($input === false) {
    throw new RuntimeException('Unable to read worker input.');
}

$job = json_decode($input, true, flags: JSON_THROW_ON_ERROR);

if (
    ! is_array($job)
    || ! is_string($job['turn_id'] ?? null)
    || $job['turn_id'] === ''
    || ! is_string($job['source_text'] ?? null)
    || $job['source_text'] === ''
    || ! is_string($job['source_language'] ?? null)
    || ! is_string($job['target_language'] ?? null)
) {
    throw new RuntimeException('Invalid worker job.');
}

$processor = new ConversationPipelineProcessor(
    translation: new FakeTranslationProvider('Hello from the worker.'),
    textToSpeech: new FakeStreamingTextToSpeechProvider(
        audio: "\x01\x02\x03\x04",
        chunkSizeBytes: 2,
    ),
);

$result = $processor->process(
    sourceText: $job['source_text'],
    sourceLanguage: Language::from($job['source_language']),
    targetLanguage: Language::from($job['target_language']),
);

echo json_encode([
    'turn_id' => $job['turn_id'],
    'status' => 'completed',
    'translated_text' => $result['translated_text'],
    'audio_base64' => base64_encode($result['audio']),
    'mime_type' => $result['mime_type'],
], JSON_THROW_ON_ERROR);
