<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuestRequest;
use App\Http\Resources\QuestResource;
use App\Models\Game;
use App\Models\Quest;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class QuestController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(QuestRequest $request, Game $game): JsonResponse
    {
        $quest = new Quest($request->validated());
        $quest->game_id = $game->id;
        $quest->source = 'manual';
        if ($quest->is_active) {
            $quest->published_at = now();
        }
        $quest->save();

        $this->audit->log('quest.created', $quest, $quest->only(['title', 'difficulty', 'xp_reward', 'is_active']));

        return (new QuestResource($quest))->response()->setStatusCode(201);
    }

    public function update(QuestRequest $request, Game $game, Quest $quest): QuestResource
    {
        $quest->fill($request->validated());
        if ($quest->is_active && $quest->published_at === null) {
            $quest->published_at = now();
        }
        $diff = AuditLogger::diff($quest);
        $quest->save();

        $this->audit->log('quest.updated', $quest, $diff);

        return new QuestResource($quest);
    }

    public function destroy(Game $game, Quest $quest): Response
    {
        $quest->delete();
        $this->audit->log('quest.deleted', $quest);

        return response()->noContent();
    }

    /** Drafts (including AI-generated ones) only go live through this explicit step. */
    public function publish(Game $game, Quest $quest): QuestResource
    {
        $quest->forceFill(['is_active' => true, 'published_at' => $quest->published_at ?? now()]);
        $diff = AuditLogger::diff($quest);
        $quest->save();

        $this->audit->log('quest.published', $quest, $diff);

        return new QuestResource($quest);
    }
}
