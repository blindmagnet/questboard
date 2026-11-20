<?php

namespace App\Domain\Outbox;

/** Domain event fired once per outbox row; queued listeners project it. */
final class OutboxEventPublished
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly ?int $gameId,
        public readonly array $payload,
        public readonly string $occurredAt,
    ) {}
}
