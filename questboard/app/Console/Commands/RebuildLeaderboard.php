<?php

namespace App\Console\Commands;

use App\Domain\Leaderboard\RedisLeaderboard;
use App\Models\Game;
use Illuminate\Console\Command;

class RebuildLeaderboard extends Command
{
    protected $signature = 'leaderboard:rebuild {game? : Game slug (all games when omitted)}';

    protected $description = 'Rebuild Redis leaderboards from MySQL (source of truth)';

    public function handle(RedisLeaderboard $leaderboard): int
    {
        $games = $this->argument('game')
            ? Game::query()->where('slug', $this->argument('game'))->get()
            : Game::query()->where('is_active', true)->get();

        if ($games->isEmpty()) {
            $this->error('No matching game.');

            return self::FAILURE;
        }

        foreach ($games as $game) {
            $count = $leaderboard->rebuild($game);
            $this->info("{$game->slug}: rebuilt with {$count} player(s).");
        }

        return self::SUCCESS;
    }
}
