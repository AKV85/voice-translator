<?php

namespace App\Services\Live;

use App\Contracts\CancellableStreamingSpeechToTextProvider;
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

    private SpeechCancellationToken $cancellationToken;

    private ?Fiber $fiber = null;

    private ?StreamingTranscriptionResult $result = null;

    private ?Throwable $exception = null;

    private bool $cancelled = false;

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
        $this->cancellationToken = new SpeechCancellationToken;
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
                    if ($this->speechToText instanceof CancellableStreamingSpeechToTextProvider) {
                        $this->result = $this->speechToText->transcribeCancellable(

                            audioChunks: $this->audioStream->chunks(),
                            mimeType: $this->mimeType,
                            sourceLanguage: $this->sourceLanguage,
                            onTranscript: function (
                                string $text,
                                bool $isFinal,
                            ): void {
                                if (! $this->cancelled) {
                                    ($this->onTranscript)($text, $isFinal);
                                }
                            },
                            cancellationToken: $this->cancellationToken,
                        );
                    } else {
                        $this->result = $this->speechToText->transcribe(

                            audioChunks: $this->audioStream->chunks(),
                            mimeType: $this->mimeType,
                            sourceLanguage: $this->sourceLanguage,
                            onTranscript: function (
                                string $text,
                                bool $isFinal,
                            ): void {
                                if (! $this->cancelled) {
                                    ($this->onTranscript)($text, $isFinal);
                                }
                            },
                        );
                    }
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

    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;

        try {
            $this->cancellationToken->cancel();
        } finally {
            $this->audioStream->cancel();
        }
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function isRunning(): bool
    {
        return ! $this->cancelled
            && $this->fiber !== null
            && ! $this->fiber->isTerminated();
    }

    private function ensureRunning(): void
    {
        if ($this->cancelled) {
            throw new LogicException(
                'Live speech recognition session was cancelled.',
            );
        }

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
