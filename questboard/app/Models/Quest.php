<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToGame;
use Database\Factories\QuestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $game_id
 * @property string $title
 * @property ?string $description
 * @property string $difficulty
 * @property int $xp_reward
 * @property int $max_score
 * @property int $min_duration_ms
 * @property int $cooldown_seconds
 * @property bool $is_repeatable
 * @property bool $is_active
 * @property string $source
 * @property ?Carbon $published_at
 */
class Quest extends Model
{
    /** @use HasFactory<QuestFactory> */
    use BelongsToGame, HasFactory, SoftDeletes;

    public const DIFFICULTIES = ['easy', 'medium', 'hard'];

    protected $attributes = [
        'difficulty' => 'easy', 'max_score' => 100, 'min_duration_ms' => 0, 'cooldown_seconds' => 0,
        'is_repeatable' => false, 'is_active' => false, 'source' => 'manual',
    ];

    protected $fillable = [
        'title', 'description', 'difficulty', 'xp_reward', 'max_score', 'min_duration_ms',
        'cooldown_seconds', 'is_repeatable', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'xp_reward' => 'integer',
            'max_score' => 'integer',
            'min_duration_ms' => 'integer',
            'cooldown_seconds' => 'integer',
            'is_repeatable' => 'boolean',
            'is_active' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /** @param Builder<Quest> $query */
    public function scopePlayable(Builder $query): void
    {
        $query->where('is_active', true)->whereNotNull('published_at');
    }

    public function isPlayable(): bool
    {
        return $this->is_active && $this->published_at !== null;
    }
}
