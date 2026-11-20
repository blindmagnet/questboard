<?php

namespace App\Http\Requests;

use App\Domain\Achievements\AchievementEvaluator;
use App\Models\Quest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AchievementRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $game = $this->route('game');

        return [
            'key' => [$required, 'string', 'max:64', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('achievements', 'key')->where('game_id', $game->id)->ignore($this->route('achievement'))],
            'name' => [$required, 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:191'],
            'icon' => ['nullable', 'string', 'max:191'],
            'rule' => [$required, 'array:type,gte,difficulty'],
            'rule.type' => ['required_with:rule', Rule::in(AchievementEvaluator::RULE_TYPES)],
            'rule.gte' => ['required_with:rule', 'integer', 'min:1', 'max:1000000'],
            'rule.difficulty' => ['sometimes', Rule::in(Quest::DIFFICULTIES)],
        ];
    }
}
