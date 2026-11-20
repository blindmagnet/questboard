<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property ?int $game_id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int $attempts
 * @property Carbon $available_at
 * @property Carbon $created_at
 */
class OutboxEvent extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    protected $attributes = ['status' => self::STATUS_PENDING, 'attempts' => 0];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
