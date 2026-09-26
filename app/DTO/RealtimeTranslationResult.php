<?php

namespace App\DTO;

final readonly class RealtimeTranslationResult
{
    public function __construct(
        public ?string $sourceTranscript,
        public string $translatedTranscript,
        public string $translatedAudio,

        public ?float $firstSourceTranscriptMs,
        public ?float $firstTranslatedTranscriptMs,

        public ?float $firstInputAudioSentMs,
        public ?float $lastInputAudioSentMs,
        public float $inputFinishedMs,

        public ?float $firstAudioPacketMs,
        public ?float $leadingSilenceMs,
        public ?float $rawPlaybackFirstAudibleAudioMs,
        public ?float $firstAudibleAudioAvailableMs,

        public float $sessionClosedMs,

        public ?float $firstAudioPacketRelativeToInputEndMs,
        public ?float $rawPlaybackFirstAudibleAudioRelativeToInputEndMs,

        public ?float $firstAudibleAudioAvailableRelativeToInputEndMs,
        public ?float $firstAudibleAudioAvailableRelativeToFirstInputMs,
        public ?float $firstAudibleAudioAvailableRelativeToLastInputMs,

        public ?float $inputAudioStreamDurationMs,
        public float $translatedAudioDurationMs,
    ) {}
}
