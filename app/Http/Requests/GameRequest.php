<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GameRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:191'],
            'slug' => [$required, 'string', 'max:64', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('games', 'slug')->ignore($this->route('game'))],
            'quota_llm_tokens_monthly' => ['sometimes', 'integer', 'min:0'],
            'quota_daily_xp_per_player' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
