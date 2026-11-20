<?php

namespace App\Http\Controllers\V1\Admin;

use App\Domain\Seasons\SeasonManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\SeasonRequest;
use App\Http\Resources\SeasonResource;
use App\Models\Game;
use App\Models\Season;
use App\Support\AuditLogger;
use App\Support\ProblemException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SeasonController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly SeasonManager $seasons) {}

    public function index(Game $game): AnonymousResourceCollection
    {
        return SeasonResource::collection(Season::query()->orderByDesc('starts_at')->cursorPaginate(25));
    }

    public function show(Game $game, Season $season): SeasonResource
    {
        return new SeasonResource($season);
    }

    public function store(SeasonRequest $request, Game $game): JsonResponse
    {
        $season = new Season($request->validated());
        $season->game_id = $game->id;
        $season->status = Season::STATUS_SCHEDULED;

        $this->assertNoOverlap($season);
        $season->save();
        if ($season->starts_at->lte(now())) {
            $this->seasons->activate($season);
        }

        $this->audit->log('season.created', $season, $request->validated());

        return (new SeasonResource($season->refresh()))->response()->setStatusCode(201);
    }

    public function update(SeasonRequest $request, Game $game, Season $season): SeasonResource
    {
        if ($season->status === Season::STATUS_CLOSED) {
            throw new ProblemException(409, 'season-closed', 'Closed seasons are frozen.');
        }

        $season->fill($request->validated());
        if ($season->ends_at->lte($season->starts_at)) {
            throw new ProblemException(422, 'invalid-season', 'ends_at must be after starts_at.');
        }
        $this->assertNoOverlap($season);
        $diff = AuditLogger::diff($season);
        $season->save();
        $this->audit->log('season.updated', $season, $diff);

        return new SeasonResource($season);
    }

    private function assertNoOverlap(Season $season): void
    {
        $overlap = Season::query()->where('status', '!=', Season::STATUS_CLOSED)
            ->when($season->exists, fn ($q) => $q->whereKeyNot($season->id))
            ->where('starts_at', '<', $season->ends_at)->where('ends_at', '>', $season->starts_at)
            ->exists();

        if ($overlap) {
            throw new ProblemException(409, 'season-overlap', 'A game can only have one season at a time; this one overlaps an existing season.');
        }
    }

    public function destroy(Game $game, Season $season): Response
    {
        if ($season->status !== Season::STATUS_SCHEDULED) {
            throw new ProblemException(409, 'season-started', 'Only scheduled seasons can be deleted.');
        }

        $season->delete();
        $this->audit->log('season.deleted', $season);

        return response()->noContent();
    }

    public function close(Game $game, Season $season): SeasonResource
    {
        if ($season->status !== Season::STATUS_ACTIVE) {
            throw new ProblemException(409, 'season-not-active', 'Only the active season can be closed.');
        }

        $this->seasons->close($season);
        $this->audit->log('season.closed', $season);

        return new SeasonResource($season->refresh());
    }
}
