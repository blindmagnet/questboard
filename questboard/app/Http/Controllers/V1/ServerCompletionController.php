<?php

namespace App\Http\Controllers\V1;

use App\Domain\Quests\Actions\CompleteQuest;
use App\Domain\Quests\CompletionInput;
use App\Http\Controllers\Controller;
use App\Http\Requests\ServerCompleteRequest;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Quest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/** Authoritative completion reported by a signed game server. */
class ServerCompletionController extends Controller
{
    public function __invoke(ServerCompleteRequest $request, Game $game, GamePlayer $player, CompleteQuest $action): JsonResponse
    {
        $quest = Quest::query()->findOrFail($request->integer('quest_id'));

        $result = $action->handle($player, $quest, new CompletionInput(
            score: $request->integer('score'),
            durationMs: $request->filled('duration_ms') ? $request->integer('duration_ms') : null,
            idempotencyKey: (string) $request->header('Idempotency-Key'),
            completedAt: $request->filled('completed_at') ? Carbon::parse($request->string('completed_at')) : null,
            reportedBy: 'server',
            clientMeta: $request->input('client_meta'),
        ));

        $status = $result->completion->status === 'flagged' ? 202 : 201;

        return response()->json(['data' => $result->toArray()], $result->replayed ? 200 : $status);
    }
}
