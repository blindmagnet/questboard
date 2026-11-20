<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToGame;
use Database\Factories\GamePlayerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $game_id
 * @property int $user_id
 * @property int $xp
 * @property int $level
 * @property int $risk_score
 * @property ?Carbon $banned_at
 * @property ?Carbon $shadow_banned_at
 * @property-read User $user
 */
class GamePlayer extends Model
{
    /** @use HasFactory<GamePlayerFactory> */
    use BelongsToGame, HasFactory;

    protected $fillable = ['game_id', 'user_id'];

    protected $attributes = ['xp' => 0, 'level' => 1, 'risk_score' => 0];

    protected function casts(): array
    {
        return [
            'xp' => 'integer',
            'level' => 'integer',
            'risk_score' => 'integer',
            'banned_at' => 'datetime',
            'shadow_banned_at' => 'datetime',
        ];
    }

    public function isBanned(): bool
    {
        return $this->banned_at !== null;
    }

    public function isShadowBanned(): bool
    {
        return $this->shadow_banned_at !== null;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<QuestCompletion, $this> */
    public function completions(): HasMany
    {
        return $this->hasMany(QuestCompletion::class, 'player_id');
    }

    /** @return BelongsToMany<Achievement, $this> */
    public function achievements(): BelongsToMany
    {
        return $this->belongsToMany(Achievement::class, 'player_achievements', 'player_id')
            ->withPivot('unlocked_at');
    }
}
