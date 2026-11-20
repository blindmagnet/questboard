<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string $scope
 * @property string $request_hash
 * @property int $response_status
 * @property array<string, mixed> $response
 */
class IdempotencyKey extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['response' => 'array', 'expires_at' => 'datetime', 'response_status' => 'integer'];
    }
}
