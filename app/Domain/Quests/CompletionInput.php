<?php

namespace App\Domain\Quests;

use Carbon\CarbonInterface;

final readonly class CompletionInput
{
    /** @param array<string, mixed>|null $clientMeta */
    public function __construct(
        public int $score,
        public ?int $durationMs,
        public string $idempotencyKey,
        public ?CarbonInterface $completedAt = null,
        public string $reportedBy = 'player',
        public ?array $clientMeta = null,
    ) {}
}
