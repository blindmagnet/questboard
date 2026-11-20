<?php

namespace App\Domain\Llm;

use App\Models\Quest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Strict schema for model output. Output is only ever parsed as data. */
class QuestSchema
{
    /**
     * @return array{valid: bool, quests: list<array<string, mixed>>, errors: list<string>}
     */
    public function parse(string $content, int $expectedCount): array
    {
        $content = trim($content);
        // Tolerate a fenced ```json block, nothing else.
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $content, $m)) {
            $content = $m[1];
        }

        $data = json_decode($content, true);
        if (! is_array($data)) {
            return ['valid' => false, 'quests' => [], 'errors' => ['Output is not valid JSON.']];
        }

        $validator = Validator::make($data, [
            'quests' => ['required', 'array', 'size:'.$expectedCount],
            'quests.*' => ['required', 'array:title,description,difficulty,xp_reward,max_score,min_duration_ms'],
            'quests.*.title' => ['required', 'string', 'min:3', 'max:120'],
            'quests.*.description' => ['required', 'string', 'max:1000'],
            'quests.*.difficulty' => ['required', Rule::in(Quest::DIFFICULTIES)],
            'quests.*.xp_reward' => ['required', 'integer', 'between:10,1000'],
            'quests.*.max_score' => ['required', 'integer', 'between:1,100000'],
            'quests.*.min_duration_ms' => ['sometimes', 'integer', 'between:0,86400000'],
        ]);

        if ($validator->fails()) {
            return ['valid' => false, 'quests' => [], 'errors' => array_values($validator->errors()->all())];
        }

        /** @var list<array<string, mixed>> $quests */
        $quests = array_values($data['quests']);

        return ['valid' => true, 'quests' => $quests, 'errors' => []];
    }
}
