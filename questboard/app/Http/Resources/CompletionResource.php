<?php

namespace App\Http\Resources;

use App\Models\QuestCompletion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin QuestCompletion */
class CompletionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'player_id' => $this->player_id,
            'quest_id' => $this->quest_id,
            'score' => $this->score,
            'duration_ms' => $this->duration_ms,
            'xp_awarded' => $this->xp_awarded,
            'status' => $this->status,
            'risk_score' => $this->risk_score,
            'risk_reasons' => $this->risk_reasons ?? [],
            'reported_by' => $this->reported_by,
            'completed_at' => $this->completed_at->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
        ];
    }
}
