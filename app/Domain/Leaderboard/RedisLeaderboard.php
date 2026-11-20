<?php

namespace App\Domain\Leaderboard;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\QuestCompletion;
use App\Models\SeasonScore;
use Carbon\CarbonInterface;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;

/**
 * Leaderboards are Redis sorted sets. MySQL stays the source of truth;
 * everything here can be rebuilt with `leaderboard:rebuild`.
 *
 * Keys: lb:{game}:all, lb:{game}:season:{id}, lb:{game}:weekly:{oYW}
 */
class RedisLeaderboard
{
    public function __construct(private readonly RedisManager $redis) {}

    private function conn(): Connection
    {
        return $this->redis->connection(config('questboard.leaderboard.connection'));
    }

    public function key(int $gameId, LeaderboardScope $scope, ?int $seasonId = null, ?CarbonInterface $week = null): string
    {
        return match ($scope) {
            LeaderboardScope::All => "lb:{$gameId}:all",
            LeaderboardScope::Season => "lb:{$gameId}:season:{$seasonId}",
            LeaderboardScope::Weekly => "lb:{$gameId}:weekly:".($week ?? now())->format('oW'),
        };
    }

    /**
     * Projects one accepted completion. De-duplicated by event id because the
     * outbox delivers at-least-once.
     */
    public function project(string $eventId, int $gameId, int $playerId, int $xp, ?int $seasonId, CarbonInterface $at): bool
    {
        $conn = $this->conn();

        if (! $conn->command('set', ["lb:processed:{$eventId}", 1, ['nx', 'ex' => 7 * 86400]])) {
            return false;
        }

        if ($xp <= 0) {
            return true;
        }

        $weekly = $this->key($gameId, LeaderboardScope::Weekly, week: $at);
        $conn->zincrby($this->key($gameId, LeaderboardScope::All), $xp, (string) $playerId);
        $conn->zincrby($weekly, $xp, (string) $playerId);
        $conn->expire($weekly, (int) config('questboard.leaderboard.weekly_ttl_days') * 86400);

        if ($seasonId !== null) {
            $conn->zincrby($this->key($gameId, LeaderboardScope::Season, $seasonId), $xp, (string) $playerId);
        }

        return true;
    }

    /** Removes a player from every live board of the game (used for bans). */
    public function remove(Game $game, int $playerId): void
    {
        foreach ($this->liveKeys($game) as $key) {
            $this->conn()->zrem($key, (string) $playerId);
        }
    }

    /**
     * Keys that are currently written to. Explicit keys are used instead of KEYS/SCAN
     * so the operation stays O(1) regardless of the Redis keyspace size.
     *
     * @return list<string>
     */
    public function liveKeys(Game $game): array
    {
        $keys = [$this->key($game->id, LeaderboardScope::All), $this->key($game->id, LeaderboardScope::Weekly)];
        if ($season = $game->activeSeason()) {
            $keys[] = $this->key($game->id, LeaderboardScope::Season, $season->id);
        }

        return $keys;
    }

    /** @return list<array{player_id: int, score: int, rank: int}> */
    public function top(string $key, int $offset, int $limit): array
    {
        $rows = $this->conn()->zrevrange($key, $offset, $offset + $limit - 1, true);

        $result = [];
        $rank = $offset;
        foreach ($rows as $member => $score) {
            $result[] = ['player_id' => (int) $member, 'score' => (int) $score, 'rank' => ++$rank];
        }

        return $result;
    }

    public function rank(string $key, int $playerId): ?int
    {
        $rank = $this->conn()->zrevrank($key, (string) $playerId);

        // phpredis returns false for unknown members.
        return is_int($rank) ? $rank + 1 : null;
    }

    public function score(string $key, int $playerId): int
    {
        return (int) $this->conn()->zscore($key, (string) $playerId);
    }

    public function count(string $key): int
    {
        return (int) $this->conn()->zcard($key);
    }

    /** Rebuilds all boards of a game from MySQL. Returns number of players written. */
    public function rebuild(Game $game): int
    {
        $conn = $this->conn();
        foreach ($this->liveKeys($game) as $key) {
            $conn->del($key);
        }

        $eligible = fn () => GamePlayer::withoutGlobalScopes()
            ->where('game_id', $game->id)
            ->whereNull('banned_at')
            ->whereNull('shadow_banned_at');

        $count = 0;
        $eligible()->where('xp', '>', 0)->select(['id', 'xp'])->orderBy('id')
            ->chunkById(1000, function ($players) use ($conn, $game, &$count) {
                $args = [];
                foreach ($players as $p) {
                    $args[(string) $p->id] = $p->xp;
                }
                $conn->zadd($this->key($game->id, LeaderboardScope::All), $args);
                $count += count($args);
            });

        if ($season = $game->activeSeason()) {
            $key = $this->key($game->id, LeaderboardScope::Season, $season->id);
            SeasonScore::query()->where('season_id', $season->id)->where('points', '>', 0)
                ->whereIn('player_id', $eligible()->select('id'))
                ->orderBy('id')->chunkById(1000, function ($scores) use ($conn, $key) {
                    $args = [];
                    foreach ($scores as $s) {
                        $args[(string) $s->player_id] = $s->points;
                    }
                    $conn->zadd($key, $args);
                });
        }

        $weekStart = now()->startOfWeek();
        $weekly = QuestCompletion::withoutGlobalScopes()
            ->where('game_id', $game->id)
            ->where('status', QuestCompletion::STATUS_ACCEPTED)
            ->where('completed_at', '>=', $weekStart)
            ->whereIn('player_id', $eligible()->select('id'))
            ->groupBy('player_id')
            ->selectRaw('player_id, SUM(xp_awarded) as total')
            ->pluck('total', 'player_id');

        if ($weekly->isNotEmpty()) {
            $key = $this->key($game->id, LeaderboardScope::Weekly);
            $conn->zadd($key, $weekly->map(fn ($v) => (int) $v)->all());
            $conn->expire($key, (int) config('questboard.leaderboard.weekly_ttl_days') * 86400);
        }

        return $count;
    }

    /**
     * Compares a random sample of the all-time board against MySQL.
     *
     * @return list<int> player ids that are out of sync
     */
    public function verifySample(Game $game, int $sampleSize = 50): array
    {
        $key = $this->key($game->id, LeaderboardScope::All);

        return GamePlayer::withoutGlobalScopes()
            ->where('game_id', $game->id)
            ->whereNull('banned_at')->whereNull('shadow_banned_at')
            ->inRandomOrder()->limit($sampleSize)->get(['id', 'xp'])
            ->filter(fn (GamePlayer $p) => $this->score($key, $p->id) !== $p->xp)
            ->pluck('id')->values()->all();
    }
}
