<?php

namespace App\Enums;

enum LivePipelineQualityIssue: string
{
    case SttWrong = 'stt_wrong';
    case TranslationWrong = 'translation_wrong';
    case MeaningChanged = 'meaning_changed';
    case NamePlaceCorrupted = 'name_place_corrupted';
    case NumberTimeWrong = 'number_time_wrong';
    case Other = 'other';
}
