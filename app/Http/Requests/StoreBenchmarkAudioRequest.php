<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBenchmarkAudioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    public function rules(): array
    {
        return [
            'phrase_id' => [
                'required',
                'string',
                'regex:/^(ru|en)-\d{3}$/',
            ],
            'audio' => [
                'required',
                'file',
                'max:10240',
                'mimetypes:audio/webm,video/webm',
            ],
        ];
    }
}
