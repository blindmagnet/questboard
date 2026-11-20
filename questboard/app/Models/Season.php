<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToGame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $game_id
 * @property string $name
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $status
 * @property bool $auto_renew
 * @property ?Carbon $closed_at
 * @property ?int $renew_days
 */
class Season extends Model
{
    use BelongsToGame;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    protected $attributes = ['status' => self::STATUS_SCHEDULED, 'auto_renew' => false];

    protected $fillable = ['name', 'starts_at', 'ends_at', 'auto_renew', 'renew_days'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'closed_at' => 'datetime',
            'auto_renew' => 'boolean',
            'renew_days' => 'integer',
        ];
    }

    /** @return HasMany<SeasonScore, $this> */
    public function scores(): HasMany
    {
        return $this->hasMany(SeasonScore::class);
    }
}
