<?php

namespace App\Http\Controllers\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\GameRequest;
use App\Http\Resources\GameResource;
use App\Models\Game;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class GameController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): AnonymousResourceCollection
    {
        return GameResource::collection(Game::query()->orderBy('id')->cursorPaginate(50));
    }

    public function show(Game $game): GameResource
    {
        return new GameResource($game);
    }

    public function store(GameRequest $request): JsonResponse
    {
        $game = Game::query()->create($request->validated());
        $credentials = $game->rotateCredentials();
        $this->audit->log('game.created', $game, $request->validated(), $game->id);

        return response()->json(['data' => [
            ...(new GameResource($game))->resolve($request),
            // Shown once; only a hash of the API key is stored.
            'credentials' => $credentials,
        ]], 201);
    }

    public function update(GameRequest $request, Game $game): GameResource
    {
        $game->fill($request->validated());
        $diff = AuditLogger::diff($game);
        $game->save();
        $this->audit->log('game.updated', $game, $diff, $game->id);

        return new GameResource($game);
    }

    public function destroy(Game $game): Response
    {
        $game->delete();
        $this->audit->log('game.deleted', $game, [], $game->id);

        return response()->noContent();
    }

    public function rotateKey(Game $game): JsonResponse
    {
        $credentials = $game->rotateCredentials();
        $this->audit->log('game.credentials_rotated', $game, ['api_key_prefix' => $game->api_key_prefix], $game->id);

        return response()->json(['data' => ['credentials' => $credentials]]);
    }

    public function addAdmin(Request $request, Game $game): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'exists:users,email']]);
        $user = User::query()->where('email', $data['email'])->firstOrFail();
        $game->admins()->syncWithoutDetaching([$user->id]);
        $this->audit->log('game.admin_added', $game, ['user_id' => $user->id], $game->id);

        return response()->json(['data' => ['game' => $game->slug, 'user_id' => $user->id]], 201);
    }
}
