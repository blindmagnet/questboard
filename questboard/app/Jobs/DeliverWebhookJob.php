<?php

namespace App\Jobs;

use App\Domain\Webhooks\WebhookDispatcher;
use App\Models\WebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $deliveryId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(WebhookDispatcher $dispatcher): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);
        if (! $delivery) {
            return;
        }

        $retryIn = $dispatcher->attempt($delivery);
        if ($retryIn !== null) {
            // Retries are tracked in the database (attempts, next_retry_at) so they survive restarts.
            self::dispatch($this->deliveryId)->delay(now()->addSeconds($retryIn));
        }
    }
}
