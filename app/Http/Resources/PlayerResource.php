<?php

namespace App\Http\Resources;

use App\Models\GamePlayer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GamePlayer */
class PlayerResource extends JsonResource
{
    /** @return array<int|string, mixed> */
    public function toArray(Request $request): array
    {
        $isAdmin = (bool) $request->attributes->get('is_game_admin');

        return [
            'id' => $this->id,
            'name' => $this->whenLoaded('user', fn () => $this->user->name),
            'xp' => $this->xp,
            'level' => $this->level,
            $this->mergeWhen($isAdmin, fn () => [
                'user_id' => $this->user_id,
                'risk_score' => $this->risk_score,
                'banned_at' => $this->banned_at?->toIso8601String(),
                'shadow_banned_at' => $this->shadow_banned_at?->toIso8601String(),
                'ban_reason' => $this->ban_reason,
            ]),
        ];
    }
}
