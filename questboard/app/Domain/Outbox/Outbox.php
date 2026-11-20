<?php

namespace App\Domain\Outbox;

use App\Models\OutboxEvent;

/**
 * Records integration events inside the caller's DB transaction (Outbox pattern).
 * Nothing is sent to the queue here, so an event can never be lost or emitted
 * for a transaction that rolled back.
 */
class Outbox
{
    /** @param array<string, mixed> $payload */
    public function record(string $type, ?int $gameId, array $payload): OutboxEvent
    {
        return OutboxEvent::query()->create([
            'game_id' => $gameId,
            'type' => $type,
            'payload' => $payload,
            'status' => OutboxEvent::STATUS_PENDING,
            'available_at' => now(),
        ]);
    }
}
