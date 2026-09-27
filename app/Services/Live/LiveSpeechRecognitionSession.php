<?php

namespace App\Services\Live;

use App\Contracts\StreamingSpeechToTextProvider;
use App\DTO\StreamingTranscriptionResult;
use App\Enums\Language;
use Closure;
use Fiber;
use LogicException;
use Throwable;

final class LiveSpeechRecognitionSession
{
    private LiveAudioStream $audioStream;

    private ?Fiber $fiber = null;

    private ?StreamingTranscriptionResult $result = null;

    private ?Throwable $exception = null;

    /**
     * @param  Closure(string, bool): void  $onTranscript
     */
    public function __construct(
        private readonly StreamingSpeechToTextProvider $speechToText,
        private readonly string $mimeType,
        private readonly Language $sourceLanguage,
        private readonly Closure $onTranscript,
    ) {
        $this->audioStream = new LiveAudioStream;
    }

    public function start(): void
    {
        if ($this->fiber !== null) {
            throw new LogicException(
                'Live speech recognition session has already started.',
            );
        }

        $this->fiber = new Fiber(
            function (): void {
                try {
                    $this->result = $this->speechToText->transcribe(
                        audioChunks: $this->audioStream->chunks(),
                        mimeType: $this->mimeType,
                        sourceLanguage: $this->sourceLanguage,
                        onTranscript: $this->onTranscript,
                    );
                } catch (Throwable $exception) {
                    $this->exception = $exception;
                }
            },
        );

        $this->fiber->start();

        $this->throwIfFailed();
    }

    public function pushAudio(
        string $chunk,
    ): void {
        $this->ensureRunning();

        $this->audioStream->push(
            $chunk,
        );

        $this->throwIfFailed();
    }

    public function finish(): StreamingTranscriptionResult
    {
        $this->ensureRunning();

        $this->audioStream->finish();

        $this->throwIfFailed();

        if ($this->result === null) {
            throw new LogicException(
                'Live speech recognition session finished without a result.',
            );
        }

        return $this->result;
    }

    public function isRunning(): bool
    {
        return $this->fiber !== null
            && ! $this->fiber->isTerminated();
    }

    private function ensureRunning(): void
    {
        if ($this->fiber === null) {
            throw new LogicException(
                'Live speech recognition session has not started.',
            );
        }

        if ($this->fiber->isTerminated()) {
            throw new LogicException(
                'Live speech recognition session has already finished.',
            );
        }
    }

    private function throwIfFailed(): void
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }
    }
}
