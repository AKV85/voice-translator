<?php

namespace Tests\Fakes;

use App\Contracts\StreamingTextToSpeechProvider;
use App\DTO\SpeechSynthesisResult;
use App\Enums\Language;
use Closure;

final class ConversationFakeTextToSpeechProvider implements StreamingTextToSpeechProvider
{
    public function synthesize(
        string $text,
        Language $targetLanguage,
        Closure $onAudioChunk,
    ): SpeechSynthesisResult {
        // Valid little-endian 16-bit PCM samples.
        $audio = pack('v*', 0, 1200, 2400, 1200, 0);

        $onAudioChunk(substr($audio, 0, 4));

        $failureFile = config('conversation.integration_failure_file');

        if (
            is_string($failureFile)
            && is_file($failureFile)
            && trim((string) file_get_contents($failureFile)) === 'tts'
        ) {
            unlink($failureFile);

            throw new \RuntimeException(
                'Simulated conversation TTS failure.',
            );
        }

        $onAudioChunk(substr($audio, 4));

        return new SpeechSynthesisResult(
            audio: $audio,
            mimeType: 'audio/pcm',
        );
    }
}
