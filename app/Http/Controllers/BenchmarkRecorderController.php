<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBenchmarkAudioRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

class BenchmarkRecorderController extends Controller
{
    public function index(): View
    {
        $this->ensureAvailable();

        $phrases = collect($this->loadPhrases())
            ->map(function (array $phrase): array {
                $phrase['completed'] = File::exists(
                    $this->audioPath($phrase['id'])
                );

                return $phrase;
            })
            ->values()
            ->all();

        return view('benchmark.recorder', [
            'phrases' => $phrases,
        ]);
    }

    public function store(StoreBenchmarkAudioRequest $request): JsonResponse
    {
        $this->ensureAvailable();

        $phraseId = $request->validated('phrase_id');

        $phrase = collect($this->loadPhrases())
            ->firstWhere('id', $phraseId);

        abort_if(
            $phrase === null,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Unknown benchmark phrase.'
        );

        $directory = base_path('docs/benchmarks/speech/audio/raw');

        File::ensureDirectoryExists($directory);

        $request->file('audio')->move(
            $directory,
            "{$phraseId}.webm"
        );

        return response()->json([
            'phrase_id' => $phraseId,
            'saved' => true,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws JsonException
     */
    private function loadPhrases(): array
    {
        $path = base_path('docs/benchmarks/speech/phrases.json');

        abort_unless(
            File::exists($path),
            Response::HTTP_INTERNAL_SERVER_ERROR,
            'Speech benchmark dataset not found.'
        );

        $dataset = json_decode(
            File::get($path),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        return $dataset['phrases'] ?? [];
    }

    private function audioPath(string $phraseId): string
    {
        return base_path(
            "docs/benchmarks/speech/audio/raw/{$phraseId}.webm"
        );
    }

    private function ensureAvailable(): void
    {
        abort_unless(
            app()->environment(['local', 'testing']),
            Response::HTTP_NOT_FOUND
        );
    }
}
