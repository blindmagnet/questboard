<?php

namespace App\Domain\Achievements;

use App\Domain\Outbox\Outbox;
use App\Models\Achievement;
use App\Models\GamePlayer;
use App\Models\QuestCompletion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Evaluates JSON achievement rules against a player's history.
 *
 * Supported rules:
 *   {"type":"completions_count","gte":10,"difficulty":"hard"}  (difficulty optional)
 *   {"type":"level_reached","gte":5}
 *   {"type":"xp_total","gte":1000}
 *   {"type":"streak_days","gte":7}
 */
class AchievementEvaluator
{
    public const RULE_TYPES = ['completions_count', 'level_reached', 'xp_total', 'streak_days'];

    public function __construct(private readonly Outbox $outbox) {}

    /**
     * Unlocks every newly satisfied achievement exactly once.
     *
     * @return list<string> keys of achievements unlocked by this call
     */
    public function evaluate(GamePlayer $player): array
    {
        $unlocked = [];

        $candidates = Achievement::withoutGlobalScopes()
            ->where('game_id', $player->game_id)
            ->whereNotIn('id', DB::table('player_achievements')->where('player_id', $player->id)->select('achievement_id'))
            ->get();

        foreach ($candidates as $achievement) {
            if (! $this->satisfies($player, $achievement->rule)) {
                continue;
            }

            DB::transaction(function () use ($player, $achievement, &$unlocked) {
                // The composite primary key makes this race-safe: only one insert wins.
                $inserted = DB::table('player_achievements')->insertOrIgnore([
                    'player_id' => $player->id,
                    'achievement_id' => $achievement->id,
                    'unlocked_at' => now(),
                ]);

                if ($inserted === 1) {
                    $unlocked[] = $achievement->key;
                    $this->outbox->record('achievement.unlocked', $player->game_id, [
                        'player_id' => $player->id,
                        'user_id' => $player->user_id,
                        'achievement' => ['id' => $achievement->id, 'key' => $achievement->key, 'name' => $achievement->name],
                    ]);
                }
            });
        }

        return $unlocked;
    }

    /** @param array<string, mixed> $rule */
    public function satisfies(GamePlayer $player, array $rule): bool
    {
        $gte = (int) ($rule['gte'] ?? 1);

        return match ($rule['type'] ?? null) {
            'completions_count' => $this->completions($player, $rule['difficulty'] ?? null) >= $gte,
            'level_reached' => $player->level >= $gte,
            'xp_total' => $player->xp >= $gte,
            'streak_days' => $this->longestStreakEndingToday($player) >= $gte,
            default => throw new InvalidArgumentException('Unknown achievement rule type.'),
        };
    }

    private function completions(GamePlayer $player, ?string $difficulty): int
    {
        return QuestCompletion::withoutGlobalScopes()
            ->where('quest_completions.player_id', $player->id)
            ->where('quest_completions.status', QuestCompletion::STATUS_ACCEPTED)
            ->when($difficulty, fn ($q) => $q->join('quests', 'quests.id', '=', 'quest_completions.quest_id')
                ->where('quests.difficulty', $difficulty))
            ->count();
    }

    /** Consecutive days (UTC) with at least one accepted completion, ending today. */
    private function longestStreakEndingToday(GamePlayer $player): int
    {
        $days = QuestCompletion::withoutGlobalScopes()
            ->where('player_id', $player->id)
            ->where('status', QuestCompletion::STATUS_ACCEPTED)
            ->where('completed_at', '>=', now()->subDays(366)->startOfDay())
            ->pluck('completed_at')
            ->map(fn ($d) => Carbon::parse($d)->utc()->toDateString())
            ->unique()
            ->flip();

        $streak = 0;
        $day = now()->utc()->startOfDay();
        while ($days->has($day->toDateString())) {
            $streak++;
            $day = $day->subDay();
        }

        return $streak;
    }
}
