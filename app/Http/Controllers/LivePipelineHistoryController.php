<?php

namespace App\Http\Controllers;

use App\Models\LivePipelineRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class LivePipelineHistoryController extends Controller
{
    public function __invoke(
        Request $request,
    ): JsonResponse {
        $filters =
            $request->validate([
                'direction' => [
                    'nullable',
                    Rule::in([
                        'ru-en',
                        'en-ru',
                    ]),
                ],

                'pipeline' => [
                    'nullable',
                    'string',
                    'max:255',
                ],

                'quality' => [
                    'nullable',
                    Rule::in([
                        'correct',
                        'acceptable',
                        'wrong',
                        'unreviewed',
                    ]),
                ],

                'date_from' => [
                    'nullable',
                    'date_format:Y-m-d',
                ],

                'date_to' => [
                    'nullable',
                    'date_format:Y-m-d',
                ],
            ]);

        $query =
            LivePipelineRun::query()
                ->latest('created_at')
                ->latest('id');

        if (isset($filters['direction'])) {
            [
                $sourceLanguage,
                $targetLanguage,
            ] = explode(
                '-',
                $filters['direction'],
                2,
            );

            $query
                ->where(
                    'source_language',
                    $sourceLanguage,
                )
                ->where(
                    'target_language',
                    $targetLanguage,
                );
        }

        if (isset($filters['pipeline'])) {
            $query->where(
                'pipeline_profile',
                $filters['pipeline'],
            );
        }

        if (isset($filters['quality'])) {
            if (
                $filters['quality']
                === 'unreviewed'
            ) {
                $query->whereNull(
                    'quality_rating',
                );
            } else {
                $query->where(
                    'quality_rating',
                    $filters['quality'],
                );
            }
        }

        if (isset($filters['date_from'])) {
            $query->whereDate(
                'created_at',
                '>=',
                $filters['date_from'],
            );
        }

        if (isset($filters['date_to'])) {
            $query->whereDate(
                'created_at',
                '<=',
                $filters['date_to'],
            );
        }

        $runs =
            $query
                ->paginate(50)
                ->withQueryString();

        return response()->json(
            $runs,
        );
    }
}
