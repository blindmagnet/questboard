<?php

namespace App\Domain\AntiCheat;

use App\Models\GamePlayer;
use App\Models\Quest;
use Carbon\CarbonInterface;

final readonly class CompletionContext
{
    public function __construct(
        public GamePlayer $player,
        public Quest $quest,
        public int $score,
        public ?int $durationMs,
        public CarbonInterface $completedAt,
        public int $recentCompletions,
        public bool $fromTrustedServer = false,
    ) {}
}
