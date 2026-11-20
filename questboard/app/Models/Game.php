<?php

namespace App\Models;

use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property ?string $api_key_hash
 * @property ?string $signing_secret
 * @property int $quota_llm_tokens_monthly
 * @property int $quota_daily_xp_per_player
 * @property bool $is_active
 */
class Game extends Model
{
    /** @use HasFactory<GameFactory> */
    use HasFactory, SoftDeletes;

    protected $attributes = ['quota_llm_tokens_monthly' => 200000, 'quota_daily_xp_per_player' => 5000, 'is_active' => true];

    protected $fillable = ['name', 'slug', 'quota_llm_tokens_monthly', 'quota_daily_xp_per_player', 'is_active'];

    protected $hidden = ['api_key_hash', 'signing_secret'];

    protected function casts(): array
    {
        return [
            'signing_secret' => 'encrypted',
            'is_active' => 'boolean',
            'quota_llm_tokens_monthly' => 'integer',
            'quota_daily_xp_per_player' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Generates a new server API key + signing secret. Only the key hash is stored;
     * the plain values are returned once to the caller.
     *
     * @return array{api_key: string, signing_secret: string}
     */
    public function rotateCredentials(): array
    {
        $prefix = Str::lower(Str::random(8));
        $apiKey = 'qb_'.$prefix.'_'.Str::random(40);
        $secret = Str::random(64);

        $this->forceFill([
            'api_key_hash' => hash('sha256', $apiKey),
            'api_key_prefix' => $prefix,
            'signing_secret' => $secret,
        ])->save();

        return ['api_key' => $apiKey, 'signing_secret' => $secret];
    }

    public static function findByApiKey(string $apiKey): ?self
    {
        return static::query()->where('api_key_hash', hash('sha256', $apiKey))->where('is_active', true)->first();
    }

    /** @return HasMany<Quest, $this> */
    public function quests(): HasMany
    {
        return $this->hasMany(Quest::class);
    }

    /** @return HasMany<GamePlayer, $this> */
    public function players(): HasMany
    {
        return $this->hasMany(GamePlayer::class);
    }

    /** @return HasMany<Achievement, $this> */
    public function achievements(): HasMany
    {
        return $this->hasMany(Achievement::class);
    }

    /** @return HasMany<Season, $this> */
    public function seasons(): HasMany
    {
        return $this->hasMany(Season::class);
    }

    /** @return HasMany<WebhookEndpoint, $this> */
    public function webhooks(): HasMany
    {
        return $this->hasMany(WebhookEndpoint::class);
    }

    /** @return HasMany<LlmGeneration, $this> */
    public function generations(): HasMany
    {
        return $this->hasMany(LlmGeneration::class);
    }

    /** @return HasMany<QuestCompletion, $this> */
    public function completions(): HasMany
    {
        return $this->hasMany(QuestCompletion::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function admins(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'game_admins')->withTimestamps();
    }

    public function activeSeason(): ?Season
    {
        return Season::withoutGlobalScopes()
            ->where('game_id', $this->id)
            ->where('status', Season::STATUS_ACTIVE)
            ->first();
    }
}
