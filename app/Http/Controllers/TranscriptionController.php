<?php

namespace App\Http\Controllers;

use App\Contracts\SpeechToTextProvider;
use App\DTO\AudioInput;
use App\Enums\Language;
use App\Exceptions\SpeechRecognitionException;
use App\Http\Requests\TranscribeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

final class TranscriptionController extends Controller
{
    public function __invoke(
        TranscribeRequest $request,
        SpeechToTextProvider $speechToText,
    ): JsonResponse {
        /** @var UploadedFile $file */
        $file = $request->file('audio');

        $language = Language::from(
            $request->string('language')->toString(),
        );

        $audio = new AudioInput(
            content: $file->getContent(),
            mimeType: $file->getMimeType()
                ?? $file->getClientMimeType(),
        );

        try {
            $result = $speechToText->transcribe(
                audio: $audio,
                sourceLanguage: $language,
            );
        } catch (SpeechRecognitionException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Speech could not be recognized.',
            ], 422);
        }

        return response()->json([
            'text' => $result->text,
        ]);
    }
}
