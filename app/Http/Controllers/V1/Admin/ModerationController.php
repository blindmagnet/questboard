<?php

namespace App\Http\Controllers\V1\Admin;

use App\Domain\Leaderboard\RedisLeaderboard;
use App\Domain\Quests\Actions\ReviewCompletion;
use App\Http\Controllers\Controller;
use App\Http\Resources\CompletionResource;
use App\Http\Resources\PlayerResource;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\QuestCompletion;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ModerationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly RedisLeaderboard $leaderboard) {}

    public function ban(Request $request, Game $game, GamePlayer $player): PlayerResource
    {
        $data = $request->validate([
            'shadow' => ['sometimes', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:191'],
        ]);

        $column = ($data['shadow'] ?? false) ? 'shadow_banned_at' : 'banned_at';
        $player->forceFill([$column => now(), 'ban_reason' => $data['reason'] ?? null]);
        $diff = AuditLogger::diff($player);
        $player->save();

        $this->leaderboard->remove($game, $player->id);
        $this->audit->log($column === 'banned_at' ? 'player.banned' : 'player.shadow_banned', $player, $diff);

        return new PlayerResource($player->load('user'));
    }

    public function unban(Game $game, GamePlayer $player): PlayerResource
    {
        $player->forceFill(['banned_at' => null, 'shadow_banned_at' => null, 'ban_reason' => null]);
        $diff = AuditLogger::diff($player);
        $player->save();
        // Rare admin action: rebuilding from MySQL restores the player on every board (all-time, weekly, season).
        $this->leaderboard->rebuild($game);
        $this->audit->log('player.unbanned', $player, $diff);

        return new PlayerResource($player->load('user'));
    }

    public function flags(Request $request, Game $game): JsonResponse
    {
        $completions = QuestCompletion::query()->where('status', QuestCompletion::STATUS_FLAGGED)
            ->orderByDesc('id')->limit(100)->get();
        $players = GamePlayer::query()->with('user')
            ->where('risk_score', '>=', (int) config('questboard.anti_cheat.review_threshold'))
            ->orderByDesc('risk_score')->limit(100)->get();

        return response()->json(['data' => [
            'completions' => CompletionResource::collection($completions),
            'players' => PlayerResource::collection($players),
        ]]);
    }

    public function approve(Game $game, QuestCompletion $completion, ReviewCompletion $review): CompletionResource
    {
        return new CompletionResource($review->approve($completion));
    }

    public function reject(Game $game, QuestCompletion $completion, ReviewCompletion $review): CompletionResource
    {
        return new CompletionResource($review->reject($completion));
    }
}
