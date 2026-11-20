<?php

namespace App\Http\Controllers\V1;

use App\Domain\Leaderboard\LeaderboardScope;
use App\Domain\Leaderboard\RedisLeaderboard;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Season;
use App\Models\SeasonScore;
use App\Support\ProblemException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Reads only from Redis (or the frozen MySQL snapshot for closed seasons). */
class LeaderboardController extends Controller
{
    public function __construct(private readonly RedisLeaderboard $leaderboard) {}

    public function index(Request $request, Game $game): JsonResponse
    {
        $request->validate([
            'scope' => ['sometimes', Rule::enum(LeaderboardScope::class)],
            'season_id' => ['sometimes', 'integer'],
            'limit' => ['sometimes', 'integer', 'between:1,'.config('questboard.leaderboard.max_page_size')],
            'cursor' => ['sometimes', 'string', 'max:64'],
        ]);

        $scope = LeaderboardScope::from($request->input('scope', 'all'));
        $limit = (int) $request->input('limit', 25);
        $offset = $this->decodeCursor($request->input('cursor'));

        $season = $scope === LeaderboardScope::Season ? $this->season($game, $request->integer('season_id') ?: null) : null;

        if ($season && $season->status === Season::STATUS_CLOSED) {
            $rows = SeasonScore::query()->where('season_id', $season->id)->whereNotNull('final_rank')
                ->orderBy('final_rank')->skip($offset)->take($limit)->get()
                ->map(fn (SeasonScore $s) => ['player_id' => $s->player_id, 'score' => $s->points, 'rank' => (int) $s->final_rank])
                ->all();
            $total = SeasonScore::query()->where('season_id', $season->id)->whereNotNull('final_rank')->count();
        } else {
            $key = $this->leaderboard->key($game->id, $scope, $season?->id);
            $rows = $this->leaderboard->top($key, $offset, $limit);
            $total = $this->leaderboard->count($key);
        }

        $next = $offset + $limit < $total ? $this->encodeCursor($offset + $limit) : null;

        return response()->json([
            'data' => $this->withNames($rows),
            'meta' => [
                'scope' => $scope->value,
                'season_id' => $season?->id,
                'total' => $total,
                'next_cursor' => $next,
            ],
        ])->setPrivate()->setMaxAge(5);
    }

    public function me(Request $request, Game $game): JsonResponse
    {
        $request->validate(['scope' => ['sometimes', Rule::enum(LeaderboardScope::class)]]);

        /** @var GamePlayer|null $player */
        $player = $request->attributes->get('player');
        if (! $player) {
            throw new ProblemException(404, 'not-a-player', 'You have no player profile in this game.');
        }

        $scope = LeaderboardScope::from($request->input('scope', 'all'));
        $season = $scope === LeaderboardScope::Season ? $this->season($game, null) : null;
        $key = $this->leaderboard->key($game->id, $scope, $season?->id);

        $rank = $this->leaderboard->rank($key, $player->id);
        $neighbours = [];
        if ($rank !== null) {
            $n = (int) config('questboard.leaderboard.neighbours');
            $start = max(0, $rank - 1 - $n);
            $neighbours = $this->withNames($this->leaderboard->top($key, $start, $rank - 1 - $start + $n + 1));
        }

        return response()->json(['data' => [
            'player_id' => $player->id,
            'rank' => $rank,
            'score' => $rank !== null ? $this->leaderboard->score($key, $player->id) : 0,
            'neighbours' => $neighbours,
        ]]);
    }

    private function season(Game $game, ?int $seasonId): Season
    {
        $season = $seasonId ? Season::query()->find($seasonId) : $game->activeSeason();
        if (! $season) {
            throw new ProblemException(404, 'no-season', 'No such season (and no active season).');
        }

        return $season;
    }

    /**
     * @param  list<array{player_id: int, score: int, rank: int}>  $rows
     * @return list<array{player_id: int, name: ?string, score: int, rank: int}>
     */
    private function withNames(array $rows): array
    {
        $names = GamePlayer::query()->whereIn('game_players.id', array_column($rows, 'player_id'))
            ->join('users', 'users.id', '=', 'game_players.user_id')
            ->pluck('users.name', 'game_players.id');

        return array_map(fn ($r) => ['rank' => $r['rank'], 'player_id' => $r['player_id'], 'name' => $names[$r['player_id']] ?? null, 'score' => $r['score']], $rows);
    }

    private function encodeCursor(int $offset): string
    {
        return rtrim(strtr(base64_encode('o:'.$offset), '+/', '-_'), '=');
    }

    private function decodeCursor(?string $cursor): int
    {
        if (! $cursor) {
            return 0;
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (! $decoded || ! preg_match('/^o:(\d{1,9})$/', $decoded, $m)) {
            throw new ProblemException(400, 'invalid-cursor', 'Invalid cursor.');
        }

        return (int) $m[1];
    }
}
