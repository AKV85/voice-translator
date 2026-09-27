<?php

namespace App\Http\Controllers;

use App\Enums\Language;
use App\Enums\LivePipelineQualityIssue;
use App\Enums\LivePipelineQualityRating;
use App\Models\LivePipelineRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class LivePipelineRunController extends Controller
{
    public function store(
        Request $request,
    ): JsonResponse {
        $validated =
            $request->validate([
                'comparison_id' => [
                    'nullable',
                    'uuid',
                ],

                'comparison_round' => [
                    'nullable',
                    'integer',
                    'min:1',
                ],

                'pipeline_profile' => [
                    'required',
                    'string',
                    'max:255',
                ],

                'source_language' => [
                    'required',
                    Rule::enum(
                        Language::class,
                    ),
                ],

                'input_audio_duration_ms' => [
                    'nullable',
                    'numeric',
                    'min:0',
                ],

                'stt_transcript' => [
                    'nullable',
                    'string',
                ],

                'translated_text' => [
                    'nullable',
                    'string',
                ],

                'success' => [
                    'required',
                    'boolean',
                ],

                'failed_stage' => [
                    'nullable',
                    'string',
                    'max:255',
                ],

                'error_message' => [
                    'nullable',
                    'string',
                ],

                'metrics' => [
                    'nullable',
                    'array',
                ],
            ]);

        $sourceLanguage =
            Language::from(
                $validated[
                    'source_language'
                ],
            );

        $targetLanguage =
            match ($sourceLanguage) {
                Language::Russian => Language::English,

                Language::English => Language::Russian,
            };

        $run =
            LivePipelineRun::query()->create([
                ...$validated,

                'target_language' => $targetLanguage->value,
            ]);

        return response()->json(
            [
                'id' => $run->id,
            ],
            201,
        );
    }

    public function updateQuality(
        Request $request,
        LivePipelineRun $livePipelineRun,
    ): JsonResponse {
        $validated =
            $request->validate([
                'quality_rating' => [
                    'required',
                    Rule::enum(
                        LivePipelineQualityRating::class,
                    ),
                ],

                'quality_issues' => [
                    'nullable',
                    'array',
                ],

                'quality_issues.*' => [
                    'string',
                    Rule::enum(
                        LivePipelineQualityIssue::class,
                    ),
                ],
            ]);

        $qualityRating =
            LivePipelineQualityRating::from(
                $validated['quality_rating'],
            );

        $qualityIssues =
            $qualityRating
                === LivePipelineQualityRating::Wrong
                    ? array_values(
                        array_unique(
                            $validated['quality_issues']
                            ?? [],
                        ),
                    )
                    : null;

        $livePipelineRun->update([
            'quality_rating' => $qualityRating->value,

            'quality_issues' => $qualityIssues,

            'reviewed_at' => now(),
        ]);

        return response()->json([
            'id' => $livePipelineRun->id,

            'quality_rating' => $livePipelineRun->quality_rating,

            'quality_issues' => $livePipelineRun->quality_issues,

            'reviewed_at' => $livePipelineRun->reviewed_at,
        ]);
    }
}
