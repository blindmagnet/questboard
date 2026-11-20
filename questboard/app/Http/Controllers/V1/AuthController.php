<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\ProblemException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private const MAX_FAILED_LOGINS = 5;

    private const LOCKOUT_SECONDS = 900;

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::query()->create($request->safe()->only(['name', 'email', 'password']));

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'token' => $user->createToken($request->input('device_name', 'api'))->plainTextToken,
            ],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $throttleKey = 'login:'.Str::lower((string) $request->input('email')).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_FAILED_LOGINS)) {
            throw new ProblemException(429, 'account-locked', 'Too many failed login attempts. Try again later.', [
                'retry_after' => RateLimiter::availableIn($throttleKey),
            ]);
        }

        $user = User::query()->where('email', $request->input('email'))->whereNull('anonymized_at')->first();

        if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
            RateLimiter::hit($throttleKey, self::LOCKOUT_SECONDS);
            throw new ProblemException(401, 'invalid-credentials', 'The provided credentials are incorrect.');
        }

        RateLimiter::clear($throttleKey);

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'token' => $user->createToken($request->input('device_name', 'api'))->plainTextToken,
            ],
        ]);
    }

    public function logout(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $user->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => [
            ...(new UserResource($user))->resolve($request),
            'games' => $user->playerProfiles()->withoutGlobalScopes()->whereHas('game')->with('game:id,name,slug')->get()
                ->map(fn ($p) => ['slug' => $p->game->slug, 'name' => $p->game->name, 'xp' => $p->xp, 'level' => $p->level]),
            'administered_games' => $user->administeredGames()->pluck('slug'),
        ]]);
    }

    /** GDPR-style account deletion: personal data is anonymized, gameplay history kept. */
    public function destroy(Request $request, AuditLogger $audit): Response
    {
        /** @var User $user */
        $user = $request->user();

        DB::transaction(function () use ($user, $audit) {
            $audit->log('user.anonymized', $user);
            $user->tokens()->delete();
            $user->forceFill([
                'name' => 'Deleted user',
                'email' => 'deleted+'.$user->id.'@invalid.local',
                'password' => Str::random(64),
                'anonymized_at' => now(),
            ])->save();
        });

        return response()->noContent();
    }
}
