<?php

namespace App\Http\Resources;

use App\Models\Quest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Quest */
class QuestResource extends JsonResource
{
    /** @return array<int|string, mixed> */
    public function toArray(Request $request): array
    {
        $isAdmin = (bool) $request->attributes->get('is_game_admin');

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'difficulty' => $this->difficulty,
            'xp_reward' => $this->xp_reward,
            'max_score' => $this->max_score,
            'cooldown_seconds' => $this->cooldown_seconds,
            'is_repeatable' => $this->is_repeatable,
            'published_at' => $this->published_at?->toIso8601String(),
            $this->mergeWhen($isAdmin, fn () => [
                'is_active' => $this->is_active,
                'min_duration_ms' => $this->min_duration_ms,
                'source' => $this->source,
                'prompt_version_id' => $this->prompt_version_id,
                'llm_generation_id' => $this->llm_generation_id,
                'created_at' => $this->created_at?->toIso8601String(),
            ]),
        ];
    }
}
