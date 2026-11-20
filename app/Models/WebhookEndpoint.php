<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToGame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $game_id
 * @property string $url
 * @property string $secret
 * @property array<int, string> $events
 * @property bool $is_active
 * @property int $consecutive_failures
 * @property ?Carbon $disabled_at
 * @property ?Carbon $created_at
 */
class WebhookEndpoint extends Model
{
    use BelongsToGame;

    public const EVENTS = ['quest.completed', 'achievement.unlocked', 'season.closed', 'player.flagged'];

    protected $attributes = ['is_active' => true, 'consecutive_failures' => 0];

    protected $fillable = ['url', 'events', 'is_active'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'is_active' => 'boolean',
            'consecutive_failures' => 'integer',
            'disabled_at' => 'datetime',
        ];
    }

    public function subscribesTo(string $type): bool
    {
        return in_array($type, $this->events, true);
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'endpoint_id');
    }
}
