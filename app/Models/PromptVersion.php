<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property int $version
 * @property string $system_prompt
 * @property string $template
 * @property bool $is_active
 */
class PromptVersion extends Model
{
    public const QUEST_GENERATOR = 'quest_generator';

    protected $attributes = ['is_active' => false];

    protected $fillable = ['name', 'version', 'system_prompt', 'template', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'version' => 'integer'];
    }

    public static function active(string $name): ?self
    {
        return static::query()->where('name', $name)->where('is_active', true)->latest('version')->first();
    }
}
