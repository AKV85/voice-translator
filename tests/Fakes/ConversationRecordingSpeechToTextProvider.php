<?php

namespace Tests\Fakes;

use App\Contracts\StreamingSpeechToTextProvider;
use App\DTO\StreamingTranscriptionResult;
use App\Enums\Language;
use Closure;
use RuntimeException;

final class ConversationRecordingSpeechToTextProvider implements StreamingSpeechToTextProvider
{
    public function transcribe(
        iterable $audioChunks,
        string $mimeType,
        Language $sourceLanguage,
        Closure $onTranscript,
    ): StreamingTranscriptionResult {
        $path = config('conversation.integration_audio_log');

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Integration audio log is not configured.');
        }

        foreach ($audioChunks as $chunk) {
            if ($chunk === 'FAIL_AUDIO_CHUNK') {
                throw new RuntimeException(
                    'Simulated conversation audio failure.',
                );
            }

            if ($chunk !== '') {
                if (file_put_contents($path, $chunk, FILE_APPEND | LOCK_EX) === false) {
                    throw new RuntimeException('Could not record test audio.');
                }
            }
        }

        $text = 'Integration test transcript';

        $onTranscript($text, true);

        return new StreamingTranscriptionResult(text: $text);
    }
}
