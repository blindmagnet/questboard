<?php

namespace App\Console\Commands;

use App\Domain\Leaderboard\RedisLeaderboard;
use App\Models\Game;
use App\Support\Metrics;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class VerifyLeaderboard extends Command
{
    protected $signature = 'leaderboard:verify {--sample=50} {--repair : Rebuild boards that drifted}';

    protected $description = 'Compare a random sample of Redis scores with MySQL';

    public function handle(RedisLeaderboard $leaderboard, Metrics $metrics): int
    {
        $drifted = 0;
        foreach (Game::query()->where('is_active', true)->get() as $game) {
            $mismatches = $leaderboard->verifySample($game, (int) $this->option('sample'));
            if ($mismatches === []) {
                continue;
            }

            $drifted++;
            $metrics->increment('leaderboard_drift_total', ['game' => $game->slug]);
            Log::warning('leaderboard.drift', ['game_id' => $game->id, 'players' => $mismatches]);
            $this->warn("{$game->slug}: ".count($mismatches).' mismatched player(s).');

            if ($this->option('repair')) {
                $leaderboard->rebuild($game);
                $this->info("{$game->slug}: rebuilt.");
            }
        }

        return $drifted === 0 ? self::SUCCESS : self::FAILURE;
    }
}
