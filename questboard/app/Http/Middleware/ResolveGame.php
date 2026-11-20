<?php

namespace App\Http\Middleware;

use App\Models\Game;
use App\Support\Tenancy\CurrentGame;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Sets the tenant for the request from the {game} route parameter. */
class ResolveGame
{
    public function __construct(private readonly CurrentGame $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $game = $request->route('game');
        if (! $game instanceof Game) {
            $game = Game::query()->where('slug', (string) $game)->first();
        }
        if (! $game || ! $game->is_active) {
            throw new NotFoundHttpException('Game not found.');
        }

        $request->route()?->setParameter('game', $game);
        $this->current->set($game);
        Context::add('game_id', $game->id);
        $request->attributes->set('is_game_admin', (bool) $request->user()?->canManage($game));

        try {
            return $next($request);
        } finally {
            $this->current->set(null);
        }
    }
}
