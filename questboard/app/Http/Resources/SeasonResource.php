<?php

namespace App\Http\Resources;

use App\Models\Season;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Season */
class SeasonResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'auto_renew' => $this->auto_renew,
            'renew_days' => $this->renew_days,
            'closed_at' => $this->closed_at?->toIso8601String(),
        ];
    }
}
