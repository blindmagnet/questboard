<?php

namespace App\Http\Requests;

use App\Models\Quest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateQuestsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'theme' => ['required', 'string', 'min:3', 'max:200'],
            'difficulty' => ['required', Rule::in(Quest::DIFFICULTIES)],
            'count' => ['required', 'integer', 'between:1,'.config('questboard.llm.max_count')],
            'language' => ['sometimes', 'string', Rule::in(['en', 'fa', 'de', 'fr', 'es', 'tr', 'ar'])],
        ];
    }
}
