<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $endpoint_id
 * @property string $event_id
 * @property string $event_type
 * @property string $status
 * @property ?int $status_code
 * @property int $attempts
 * @property-read WebhookEndpoint $endpoint
 */
class WebhookDelivery extends Model
{
    protected $attributes = ['status' => 'pending', 'attempts' => 0];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'status_code' => 'integer',
            'next_retry_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WebhookEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'endpoint_id')->withoutGlobalScopes();
    }
}
