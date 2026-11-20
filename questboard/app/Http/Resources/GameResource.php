<?php

namespace App\Http\Resources;

use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Game */
class GameResource extends JsonResource
{
    /** @return array<int|string, mixed> */
    public function toArray(Request $request): array
    {
        $isPlatformAdmin = (bool) $request->user()?->isPlatformAdmin();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            $this->mergeWhen($isPlatformAdmin, fn () => [
                'is_active' => $this->is_active,
                'api_key_prefix' => $this->api_key_prefix,
                'quota_llm_tokens_monthly' => $this->quota_llm_tokens_monthly,
                'quota_daily_xp_per_player' => $this->quota_daily_xp_per_player,
                'created_at' => $this->created_at?->toIso8601String(),
            ]),
        ];
    }
}
