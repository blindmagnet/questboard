<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\GameResource;
use App\Http\Resources\PlayerResource;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GameController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return GameResource::collection(Game::query()->where('is_active', true)->orderBy('name')->cursorPaginate(50));
    }

    public function join(Request $request, Game $game): JsonResponse
    {
        $player = GamePlayer::query()->firstOrCreate(['game_id' => $game->id, 'user_id' => $request->user()->id]);

        return (new PlayerResource($player))->response()->setStatusCode($player->wasRecentlyCreated ? 201 : 200);
    }
}
