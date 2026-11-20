<?php

namespace App\Http\Controllers\V1;

use App\Domain\Leaderboard\LeaderboardScope;
use App\Domain\Leaderboard\RedisLeaderboard;
use App\Domain\Progression\LevelCurve;
use App\Http\Controllers\Controller;
use App\Http\Resources\AchievementResource;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Support\ProblemException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlayerController extends Controller
{
    public function me(Request $request, Game $game, LevelCurve $curve, RedisLeaderboard $leaderboard): JsonResponse
    {
        /** @var GamePlayer|null $player */
        $player = $request->attributes->get('player');
        if (! $player) {
            throw new ProblemException(404, 'not-a-player', 'You have no player profile in this game.');
        }

        $player->load('achievements');

        return response()->json(['data' => [
            'id' => $player->id,
            'xp' => $player->xp,
            'level' => $player->level,
            'next_level_xp' => $curve->xpForLevel($player->level + 1),
            'rank' => $player->isShadowBanned() ? null : $leaderboard->rank($leaderboard->key($game->id, LeaderboardScope::All), $player->id),
            'banned' => $player->isBanned(),
            'achievements' => AchievementResource::collection($player->achievements),
        ]]);
    }
}
