<?php

namespace App\Listeners;

use App\Domain\Achievements\AchievementEvaluator;
use App\Domain\Outbox\OutboxEventPublished;
use App\Jobs\PublishOutboxJob;
use App\Models\GamePlayer;
use Illuminate\Contracts\Queue\ShouldQueue;

class EvaluateAchievements implements ShouldQueue
{
    public string $queue = 'projections';

    public int $tries = 5;

    public function __construct(private readonly AchievementEvaluator $evaluator) {}

    public function shouldQueue(OutboxEventPublished $event): bool
    {
        return $event->type === 'quest.completed';
    }

    public function handle(OutboxEventPublished $event): void
    {
        $player = GamePlayer::withoutGlobalScopes()->find($event->payload['player_id'] ?? null);
        if ($player && $this->evaluator->evaluate($player) !== []) {
            PublishOutboxJob::trigger();
        }
    }
}
