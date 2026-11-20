<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Support\ProblemException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key handling for sensitive writes:
 *  - same key + same body  -> the stored response is replayed (no side effects)
 *  - same key + other body -> 422
 *  - same key still in flight -> 409
 * Only deterministic responses are stored (not 5xx or 429), so those may be retried.
 */
class Idempotency
{
    public function handle(Request $request, Closure $next, string $required = 'required'): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null || $key === '') {
            if ($required === 'required') {
                throw new ProblemException(400, 'idempotency-key-required', 'The Idempotency-Key header is required for this endpoint.');
            }

            return $next($request);
        }

        if (! preg_match('/^[A-Za-z0-9_-]{8,100}$/', $key)) {
            throw new ProblemException(400, 'idempotency-key-invalid', 'Idempotency-Key must be 8-100 characters of [A-Za-z0-9_-].');
        }

        $actor = $request->user()?->getAuthIdentifier() ?? 'server:'.($request->attributes->get('server_game_id') ?? $request->ip());
        $scope = $actor.'|'.$request->method().'|'.$request->path();
        $hash = hash('sha256', $request->getContent());

        if ($stored = $this->find($scope, $key)) {
            return $this->replay($stored, $hash);
        }

        $lock = Cache::lock('idem:'.hash('sha256', $scope.$key), (int) config('questboard.idempotency.lock_seconds'));
        if (! $lock->get()) {
            throw new ProblemException(409, 'idempotency-in-progress', 'A request with this Idempotency-Key is still being processed.');
        }

        try {
            // Re-check after acquiring the lock (another request may have just finished).
            if ($stored = $this->find($scope, $key)) {
                return $this->replay($stored, $hash);
            }

            $response = $next($request);
            $status = $response->getStatusCode();

            if ($status < 500 && $status !== 429 && $response instanceof JsonResponse) {
                DB::table('idempotency_keys')->insertOrIgnore([
                    'key' => $key,
                    'scope' => $scope,
                    'request_hash' => $hash,
                    'response_status' => $status,
                    'response' => $response->getContent(),
                    'expires_at' => now()->addHours((int) config('questboard.idempotency.ttl_hours')),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    private function find(string $scope, string $key): ?IdempotencyKey
    {
        return IdempotencyKey::query()
            ->where('scope', $scope)->where('key', $key)
            ->where('expires_at', '>', now())
            ->first();
    }

    private function replay(IdempotencyKey $stored, string $hash): Response
    {
        if (! hash_equals($stored->request_hash, $hash)) {
            throw new ProblemException(422, 'idempotency-key-mismatch', 'This Idempotency-Key was used with a different request body.');
        }

        return response()->json($stored->response, $stored->response_status, [
            'Idempotent-Replayed' => 'true',
        ]);
    }
}
