<?php

namespace App\Enums;

enum LivePipelineQualityRating: string
{
    case Correct = 'correct';
    case Acceptable = 'acceptable';
    case Wrong = 'wrong';
}
