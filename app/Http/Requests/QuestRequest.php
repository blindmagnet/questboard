<?php

namespace App\Http\Requests;

use App\Models\Quest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuestRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'min:3', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'difficulty' => [$required, Rule::in(Quest::DIFFICULTIES)],
            'xp_reward' => [$required, 'integer', 'between:1,100000'],
            'max_score' => ['sometimes', 'integer', 'between:1,100000000'],
            'min_duration_ms' => ['sometimes', 'integer', 'between:0,86400000'],
            'cooldown_seconds' => ['sometimes', 'integer', 'between:0,2592000'],
            'is_repeatable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
