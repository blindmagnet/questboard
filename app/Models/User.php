<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $role
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_PLAYER = 'player';

    public const ROLE_PLATFORM_ADMIN = 'platform_admin';

    protected $attributes = ['role' => self::ROLE_PLAYER];

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'anonymized_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isPlatformAdmin(): bool
    {
        return $this->role === self::ROLE_PLATFORM_ADMIN;
    }

    /** @return BelongsToMany<Game, $this> */
    public function administeredGames(): BelongsToMany
    {
        return $this->belongsToMany(Game::class, 'game_admins')->withTimestamps();
    }

    /** @return HasMany<GamePlayer, $this> */
    public function playerProfiles(): HasMany
    {
        return $this->hasMany(GamePlayer::class);
    }

    public function canManage(Game $game): bool
    {
        return $this->isPlatformAdmin()
            || $this->administeredGames()->whereKey($game->id)->exists();
    }
}
