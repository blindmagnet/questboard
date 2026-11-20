<?php

namespace App\Listeners;

use App\Domain\Leaderboard\RedisLeaderboard;
use App\Domain\Outbox\OutboxEventPublished;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;

class ProjectLeaderboard implements ShouldQueue
{
    public string $queue = 'projections';

    public int $tries = 5;

    public function __construct(private readonly RedisLeaderboard $leaderboard) {}

    public function shouldQueue(OutboxEventPublished $event): bool
    {
        return $event->type === 'quest.completed' && $event->gameId !== null && empty($event->payload['shadow_banned']);
    }

    public function handle(OutboxEventPublished $event): void
    {
        $this->leaderboard->project(
            $event->id,
            (int) $event->gameId,
            (int) $event->payload['player_id'],
            (int) $event->payload['xp_awarded'],
            isset($event->payload['season_id']) ? (int) $event->payload['season_id'] : null,
            Carbon::parse($event->payload['completed_at']),
        );
    }
}
