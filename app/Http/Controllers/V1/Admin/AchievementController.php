<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AchievementRequest;
use App\Http\Resources\AchievementResource;
use App\Models\Achievement;
use App\Models\Game;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AchievementController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Cacheable list with a strong ETag (rarely changes). */
    public function index(Request $request, Game $game): JsonResponse
    {
        $achievements = Achievement::query()->orderBy('id')->get();
        $response = AchievementResource::collection($achievements)->response();

        $response->setEtag(hash('xxh128', (string) $response->getContent()))->setPrivate();
        $response->isNotModified($request);

        return $response;
    }

    public function store(AchievementRequest $request, Game $game): JsonResponse
    {
        $achievement = new Achievement($request->validated());
        $achievement->game_id = $game->id;
        $achievement->save();
        $this->audit->log('achievement.created', $achievement, $request->validated());

        return (new AchievementResource($achievement))->response()->setStatusCode(201);
    }

    public function update(AchievementRequest $request, Game $game, Achievement $achievement): AchievementResource
    {
        $achievement->fill($request->validated());
        $diff = AuditLogger::diff($achievement);
        $achievement->save();
        $this->audit->log('achievement.updated', $achievement, $diff);

        return new AchievementResource($achievement);
    }

    public function destroy(Game $game, Achievement $achievement): Response
    {
        $achievement->delete();
        $this->audit->log('achievement.deleted', $achievement);

        return response()->noContent();
    }
}
