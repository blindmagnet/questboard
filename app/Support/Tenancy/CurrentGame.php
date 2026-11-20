<?php

namespace App\Support\Tenancy;

use App\Models\Game;

/**
 * Holds the tenant (game) resolved for the current request.
 * Models using BelongsToGame are automatically filtered by it.
 */
class CurrentGame
{
    private ?Game $game = null;

    public function set(?Game $game): void
    {
        $this->game = $game;
    }

    public function get(): ?Game
    {
        return $this->game;
    }

    public function id(): ?int
    {
        return $this->game?->id;
    }
}
