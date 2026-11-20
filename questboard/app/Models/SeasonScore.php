<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $season_id
 * @property int $player_id
 * @property int $points
 * @property ?int $final_rank
 */
class SeasonScore extends Model
{
    protected $attributes = ['points' => 0];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['points' => 'integer', 'final_rank' => 'integer'];
    }

    /** @return BelongsTo<GamePlayer, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(GamePlayer::class, 'player_id')->withoutGlobalScopes();
    }
}
