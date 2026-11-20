<?php

namespace App\Support\Tenancy;

use App\Models\Game;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Row-level multi-tenancy: every query is constrained to the current game
 * (when one is resolved) and new rows inherit its game_id.
 */
trait BelongsToGame
{
    public static function bootBelongsToGame(): void
    {
        static::addGlobalScope('game', function (Builder $builder): void {
            $gameId = app(CurrentGame::class)->id();
            if ($gameId !== null) {
                $builder->where($builder->getModel()->qualifyColumn('game_id'), $gameId);
            }
        });

        static::creating(function ($model): void {
            if (empty($model->game_id) && ($gameId = app(CurrentGame::class)->id()) !== null) {
                $model->game_id = $gameId;
            }
        });
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
