<?php

namespace App\Http\Resources;

use App\Models\LlmGeneration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LlmGeneration */
class GenerationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'input' => $this->input,
            'prompt_version_id' => $this->prompt_version_id,
            'quest_ids' => $this->output['quest_ids'] ?? [],
            'attempts' => $this->attempts,
            'tokens_in' => $this->tokens_in,
            'tokens_out' => $this->tokens_out,
            'cost_estimate' => $this->cost_estimate,
            'moderation_result' => $this->moderation_result,
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
