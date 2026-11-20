<?php

namespace App\Http\Middleware;

use App\Domain\AntiCheat\RequestSigner;
use App\Models\Game;
use App\Support\ProblemException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates game servers: X-Api-Key (hashed lookup, bound to the {game} in the URL)
 * plus an HMAC-SHA256 signature over timestamp, method, path and body.
 * Old timestamps and re-used signatures are rejected (replay protection).
 */
class VerifyServerSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = (string) $request->header('X-Api-Key');
        $timestamp = (string) $request->header('X-Timestamp');
        $signature = (string) $request->header('X-Signature');

        $game = $apiKey !== '' ? Game::findByApiKey($apiKey) : null;
        $routeGame = $request->route('game');
        $routeSlug = $routeGame instanceof Game ? $routeGame->slug : (string) $routeGame;

        if (! $game || $game->slug !== $routeSlug || ! $game->signing_secret) {
            throw new ProblemException(401, 'invalid-api-key', 'Invalid API key for this game.');
        }

        $tolerance = (int) config('questboard.anti_cheat.signature_tolerance_seconds');
        if (! ctype_digit($timestamp) || ! RequestSigner::isFresh((int) $timestamp, $tolerance)) {
            throw new ProblemException(401, 'stale-request', 'X-Timestamp is missing or outside the allowed window.');
        }

        $payload = RequestSigner::canonical($timestamp, $request->method(), $request->path(), $request->getContent());
        if ($signature === '' || ! RequestSigner::verify($game->signing_secret, $payload, $signature)) {
            throw new ProblemException(401, 'invalid-signature', 'Request signature is invalid.');
        }

        // A valid signature may only be used once inside the tolerance window.
        if (! Cache::add('sig:'.$game->id.':'.$signature, 1, $tolerance * 2)) {
            throw new ProblemException(401, 'replayed-request', 'This signed request was already processed.');
        }

        $request->attributes->set('server_game_id', $game->id);

        return $next($request);
    }
}
