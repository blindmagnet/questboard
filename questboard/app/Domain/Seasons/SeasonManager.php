<?php

namespace App\Domain\Seasons;

use App\Domain\Leaderboard\LeaderboardScope;
use App\Domain\Leaderboard\RedisLeaderboard;
use App\Domain\Outbox\Outbox;
use App\Models\Game;
use App\Models\Season;
use App\Models\SeasonScore;
use Illuminate\Support\Facades\DB;

class SeasonManager
{
    public function __construct(private readonly Outbox $outbox, private readonly RedisLeaderboard $leaderboard) {}

    /**
     * Activates due seasons and closes expired ones. Run every minute by the scheduler.
     *
     * @return array{activated: int, closed: int}
     */
    public function tick(): array
    {
        $closed = 0;
        $activated = 0;

        Season::withoutGlobalScopes()->where('status', Season::STATUS_ACTIVE)->where('ends_at', '<=', now())
            ->each(function (Season $season) use (&$closed) {
                $this->close($season);
                $closed++;
            });

        Season::withoutGlobalScopes()->where('status', Season::STATUS_SCHEDULED)
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())
            ->orderBy('starts_at')
            ->each(function (Season $season) use (&$activated) {
                if ($this->activate($season)) {
                    $activated++;
                }
            });

        return ['activated' => $activated, 'closed' => $closed];
    }

    /** Activates a season unless the game already has an active one. */
    public function activate(Season $season): bool
    {
        return DB::transaction(function () use ($season) {
            // Serialize per game so that two activations can't both succeed.
            Game::query()->whereKey($season->game_id)->lockForUpdate()->first();

            $hasActive = Season::withoutGlobalScopes()
                ->where('game_id', $season->game_id)
                ->where('status', Season::STATUS_ACTIVE)
                ->exists();

            if ($hasActive) {
                return false;
            }

            $season->forceFill(['status' => Season::STATUS_ACTIVE])->save();

            return true;
        });
    }

    /**
     * Freezes the season: final ranks are written to MySQL (snapshot), the Redis
     * board is archived under a read-only key, and an optional next season is created.
     */
    public function close(Season $season): ?Season
    {
        $next = DB::transaction(function () use ($season) {
            /** @var Season $locked */
            $locked = Season::withoutGlobalScopes()->whereKey($season->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === Season::STATUS_CLOSED) {
                return null;
            }

            $rank = 0;
            SeasonScore::query()->where('season_id', $locked->id)
                ->orderByDesc('points')->orderBy('player_id')
                ->get(['id', 'player_id', 'points'])
                ->each(function (SeasonScore $score) use (&$rank) {
                    $score->forceFill(['final_rank' => ++$rank])->save();
                });

            $locked->forceFill(['status' => Season::STATUS_CLOSED, 'closed_at' => now()])->save();

            $top = SeasonScore::query()->where('season_id', $locked->id)->whereNotNull('final_rank')
                ->orderBy('final_rank')->limit(10)->get(['player_id', 'points', 'final_rank'])
                ->map(fn ($s) => ['player_id' => $s->player_id, 'points' => $s->points, 'rank' => $s->final_rank])
                ->all();

            $this->outbox->record('season.closed', $locked->game_id, [
                'season_id' => $locked->id,
                'name' => $locked->name,
                'participants' => $rank,
                'top' => $top,
            ]);

            if (! $locked->auto_renew || ! $locked->renew_days) {
                return null;
            }

            $next = new Season([
                'name' => $locked->name.' (next)',
                'starts_at' => $locked->ends_at,
                'ends_at' => $locked->ends_at->copy()->addDays($locked->renew_days),
                'auto_renew' => true,
                'renew_days' => $locked->renew_days,
            ]);
            $next->game_id = $locked->game_id;
            $next->status = Season::STATUS_SCHEDULED;
            $next->save();

            return $next;
        });

        $this->archiveBoard($season);

        if ($next && $next->starts_at->lte(now())) {
            $this->activate($next);
        }

        return $next;
    }

    private function archiveBoard(Season $season): void
    {
        $live = $this->leaderboard->key($season->game_id, LeaderboardScope::Season, $season->id);
        $conn = app('redis')->connection(config('questboard.leaderboard.connection'));
        if ($conn->exists($live)) {
            $conn->rename($live, $live.':archive');
        }
    }
}
