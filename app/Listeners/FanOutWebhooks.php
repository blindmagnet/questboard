<?php

namespace App\Listeners;

use App\Domain\Outbox\OutboxEventPublished;
use App\Domain\Webhooks\WebhookDispatcher;
use App\Models\WebhookEndpoint;
use Illuminate\Contracts\Queue\ShouldQueue;

class FanOutWebhooks implements ShouldQueue
{
    public string $queue = 'webhooks';

    public int $tries = 5;

    public function __construct(private readonly WebhookDispatcher $dispatcher) {}

    public function shouldQueue(OutboxEventPublished $event): bool
    {
        return $event->gameId !== null && in_array($event->type, WebhookEndpoint::EVENTS, true);
    }

    public function handle(OutboxEventPublished $event): void
    {
        $this->dispatcher->fanOut($event->id, $event->type, (int) $event->gameId);
    }
}
