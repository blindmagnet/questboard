<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToGame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $game_id
 * @property int $player_id
 * @property int $quest_id
 * @property int $score
 * @property ?int $duration_ms
 * @property int $xp_awarded
 * @property string $status
 * @property int $risk_score
 * @property ?array<int, string> $risk_reasons
 * @property Carbon $completed_at
 * @property ?Carbon $reviewed_at
 * @property-read Quest $quest
 * @property-read GamePlayer $player
 */
class QuestCompletion extends Model
{
    use BelongsToGame;

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_FLAGGED = 'flagged';

    public const STATUS_REJECTED = 'rejected';

    protected $attributes = ['xp_awarded' => 0, 'status' => self::STATUS_ACCEPTED, 'risk_score' => 0, 'reported_by' => 'player'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'duration_ms' => 'integer',
            'xp_awarded' => 'integer',
            'risk_score' => 'integer',
            'risk_reasons' => 'array',
            'client_meta' => 'array',
            'completed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Quest, $this> */
    public function quest(): BelongsTo
    {
        return $this->belongsTo(Quest::class)->withTrashed();
    }

    /** @return BelongsTo<GamePlayer, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(GamePlayer::class, 'player_id');
    }
}
