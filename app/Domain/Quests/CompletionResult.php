<?php

namespace App\Domain\Quests;

use App\Models\GamePlayer;
use App\Models\QuestCompletion;

final readonly class CompletionResult
{
    public function __construct(
        public QuestCompletion $completion,
        public GamePlayer $player,
        public bool $levelUp,
        public bool $replayed = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'completion_id' => $this->completion->id,
            'status' => $this->completion->status,
            'xp_awarded' => $this->completion->xp_awarded,
            'player' => ['xp' => $this->player->xp, 'level' => $this->player->level],
            'level_up' => $this->levelUp,
            // Achievements are evaluated asynchronously (see ADR-007): clients
            // learn about them via the achievement.unlocked webhook or GET /players/me.
            'achievements_pending' => $this->completion->status === QuestCompletion::STATUS_ACCEPTED,
        ];
    }
}
