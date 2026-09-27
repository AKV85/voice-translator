<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class LivePipelineRun extends Model
{
    protected $fillable = [
        'comparison_id',
        'comparison_round',
        'pipeline_profile',
        'source_language',
        'target_language',
        'input_audio_duration_ms',
        'stt_transcript',
        'translated_text',
        'success',
        'failed_stage',
        'error_message',
        'metrics',
        'quality_rating',
        'quality_issues',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'comparison_round' => 'integer',
            'input_audio_duration_ms' => 'float',
            'success' => 'boolean',
            'metrics' => 'array',
            'quality_issues' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }
}
