<?php

namespace Database\Seeders;

use App\Domain\Leaderboard\RedisLeaderboard;
use App\Domain\Progression\LevelCurve;
use App\Models\Achievement;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Quest;
use App\Models\Season;
use App\Models\SeasonScore;
use App\Models\User;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * Demo data: 2 games, admins, 20 players, quests, achievements and an active season.
 * Every demo account uses the password "password123".
 */
class DatabaseSeeder extends Seeder
{
    public function run(LevelCurve $curve, RedisLeaderboard $leaderboard): void
    {
        if (User::query()->where('email', 'admin@questboard.test')->exists()) {
            $this->command?->info('Demo data already present, skipping.');

            return;
        }

        $platformAdmin = User::factory()->platformAdmin()->create(['name' => 'Platform Admin', 'email' => 'admin@questboard.test']);

        $games = [
            ['name' => 'Ember Realms', 'slug' => 'ember', 'theme' => ['Dragon', 'Forge', 'Ash', 'Lava', 'Phoenix']],
            ['name' => 'Frostbyte', 'slug' => 'frostbyte', 'theme' => ['Glacier', 'Blizzard', 'Ice', 'Yeti', 'Aurora']],
        ];

        foreach ($games as $i => $def) {
            $game = Game::factory()->create(['name' => $def['name'], 'slug' => $def['slug']]);
            $credentials = $game->rotateCredentials();
            $this->command?->info("{$game->slug}: api_key={$credentials['api_key']} signing_secret={$credentials['signing_secret']}");

            $gameAdmin = User::factory()->create(['name' => "{$def['name']} Admin", 'email' => "{$def['slug']}-admin@questboard.test"]);
            $game->admins()->attach($gameAdmin->id);

            foreach ($def['theme'] as $n => $word) {
                Quest::factory()->create([
                    'game_id' => $game->id,
                    'title' => "{$word} Trial",
                    'difficulty' => ['easy', 'medium', 'hard'][$n % 3],
                    'xp_reward' => 50 + $n * 25,
                    'min_duration_ms' => 5000,
                    'is_repeatable' => $n % 2 === 0,
                    'cooldown_seconds' => $n % 2 === 0 ? 60 : 0,
                ]);
            }
            Quest::factory()->draft()->create(['game_id' => $game->id, 'title' => 'Unreleased Expedition']);

            foreach ([
                ['first_steps', 'First Steps', ['type' => 'completions_count', 'gte' => 1]],
                ['hard_worker', 'Hard Worker', ['type' => 'completions_count', 'difficulty' => 'hard', 'gte' => 10]],
                ['level_5', 'Seasoned', ['type' => 'level_reached', 'gte' => 5]],
                ['weekly_streak', 'On Fire', ['type' => 'streak_days', 'gte' => 7]],
            ] as [$key, $name, $rule]) {
                $achievement = new Achievement(['key' => $key, 'name' => $name, 'rule' => $rule]);
                $achievement->game_id = $game->id;
                $achievement->save();
            }

            $season = new Season(['name' => 'Season 1', 'starts_at' => now()->subDays(7), 'ends_at' => now()->addDays(23)]);
            $season->game_id = $game->id;
            $season->status = Season::STATUS_ACTIVE;
            $season->save();

            for ($p = 1; $p <= 10; $p++) {
                $user = User::factory()->create(['email' => "player{$p}@{$def['slug']}.test"]);
                $xp = random_int(0, 3000);
                $player = GamePlayer::factory()->create([
                    'game_id' => $game->id, 'user_id' => $user->id,
                ]);
                $player->forceFill(['xp' => $xp, 'level' => $curve->levelFor($xp)])->save();
                SeasonScore::query()->create(['season_id' => $season->id, 'player_id' => $player->id, 'points' => intdiv($xp, 3)]);
            }

            try {
                $leaderboard->rebuild($game);
            } catch (Throwable $e) {
                $this->command?->warn('Redis unavailable, run `php artisan leaderboard:rebuild` later: '.$e->getMessage());
            }
        }

        $this->command?->info("Platform admin: {$platformAdmin->email} / password123");
    }
}
