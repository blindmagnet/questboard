<?php

namespace App\Http\Middleware;

use App\Models\GamePlayer;
use App\Support\ProblemException;
use App\Support\Tenancy\CurrentGame;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Player endpoints require a profile in *this* game (prevents cross-tenant access).
 * Game admins of the game are allowed through without a profile.
 */
class EnsureGameMember
{
    public function __construct(private readonly CurrentGame $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $game = $this->current->get();
        $user = $request->user();
        abort_unless($game && $user, 401);

        $player = GamePlayer::query()->where('user_id', $user->id)->first();

        if (! $player && ! $user->canManage($game)) {
            throw new ProblemException(403, 'not-a-member', 'Join this game first (POST /api/v1/games/{game}/join).');
        }

        if ($player) {
            $request->attributes->set('player', $player);
            Context::add('player_id', $player->id);
        }

        return $next($request);
    }
}
