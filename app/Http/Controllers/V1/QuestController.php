<?php

namespace App\Http\Controllers\V1;

use App\Domain\Quests\Actions\CompleteQuest;
use App\Domain\Quests\CompletionInput;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteQuestRequest;
use App\Http\Resources\QuestResource;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Quest;
use App\Support\ProblemException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class QuestController extends Controller
{
    public function index(Request $request, Game $game): AnonymousResourceCollection
    {
        $request->validate([
            'difficulty' => ['sometimes', Rule::in(Quest::DIFFICULTIES)],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'include_drafts' => ['sometimes', 'boolean'],
        ]);

        $withDrafts = $request->boolean('include_drafts') && $request->attributes->get('is_game_admin');

        $quests = Quest::query()
            ->when(! $withDrafts, fn ($q) => $q->playable())
            ->when($request->input('difficulty'), fn ($q, $d) => $q->where('difficulty', $d))
            ->orderBy('id')
            ->cursorPaginate((int) $request->input('limit', 25))
            ->withQueryString();

        return QuestResource::collection($quests);
    }

    public function show(Request $request, Game $game, Quest $quest): QuestResource
    {
        if (! $quest->isPlayable() && ! $request->attributes->get('is_game_admin')) {
            throw new ProblemException(404, 'not-found', 'Quest not found.');
        }

        return new QuestResource($quest);
    }

    public function complete(CompleteQuestRequest $request, Game $game, Quest $quest, CompleteQuest $action): JsonResponse
    {
        /** @var GamePlayer|null $player */
        $player = $request->attributes->get('player');
        if (! $player) {
            throw new ProblemException(403, 'not-a-member', 'Only players can complete quests.');
        }

        $result = $action->handle($player, $quest, new CompletionInput(
            score: (int) $request->integer('score'),
            durationMs: $request->filled('duration_ms') ? $request->integer('duration_ms') : null,
            idempotencyKey: (string) $request->header('Idempotency-Key'),
            completedAt: $request->filled('completed_at') ? Carbon::parse($request->string('completed_at')) : null,
            reportedBy: 'player',
            clientMeta: $request->input('client_meta'),
        ));

        $status = $result->completion->status === 'flagged' ? 202 : 201;

        return response()->json(['data' => $result->toArray()], $result->replayed ? 200 : $status);
    }
}
