<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToGame;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $game_id
 * @property string $key
 * @property string $name
 * @property array<string, mixed> $rule
 */
class Achievement extends Model
{
    use BelongsToGame;

    protected $fillable = ['key', 'name', 'description', 'rule', 'icon'];

    protected function casts(): array
    {
        return ['rule' => 'array'];
    }
}
