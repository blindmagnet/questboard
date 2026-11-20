<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteQuestRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'score' => ['required', 'integer', 'min:0', 'max:100000000'],
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            'completed_at' => ['nullable', 'date'],
            'client_meta' => ['nullable', 'array', 'max:20'],
            'client_meta.*' => ['nullable', 'string', 'max:200'],
        ];
    }
}
