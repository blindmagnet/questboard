<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureGameMember;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\RecordRequestMetrics;
use App\Http\Middleware\ResolveGame;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyServerSignature;
use App\Support\ProblemException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$problem = function (Request $request, int $status, string $type, string $title, ?string $detail = null, array $extra = []) {
    return response()->json(array_filter([
        'type' => 'https://questboard.dev/problems/'.$type,
        'title' => $title,
        'status' => $status,
        'detail' => $detail,
        'instance' => '/'.$request->path(),
        'request_id' => Context::get('request_id'),
    ] + $extra, fn ($v) => $v !== null), $status, ['Content-Type' => 'application/problem+json']);
};

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        then: function () {
            Route::middleware([])->group(base_path('routes/ops.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->append([SecurityHeaders::class, RecordRequestMetrics::class]);
        $middleware->alias([
            'game' => ResolveGame::class,
            'member' => EnsureGameMember::class,
            'idempotent' => Idempotency::class,
            'server.signed' => VerifyServerSignature::class,
        ]);
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1'));
    })
    ->withExceptions(function (Exceptions $exceptions) use ($problem): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);

        $exceptions->render(fn (ProblemException $e, Request $request) => $problem(
            $request, $e->status, $e->type, Response::$statusTexts[$e->status] ?? 'Error', $e->getMessage(), $e->extra,
        ));
        $exceptions->render(fn (ValidationException $e, Request $request) => $problem(
            $request, 422, 'validation-error', 'Unprocessable Content', $e->getMessage(), ['errors' => $e->errors()],
        ));
        $exceptions->render(fn (AuthenticationException $e, Request $request) => $problem(
            $request, 401, 'unauthenticated', 'Unauthorized', 'Authentication is required.',
        ));
        $exceptions->render(fn (AuthorizationException $e, Request $request) => $problem(
            $request, 403, 'forbidden', 'Forbidden', 'You are not allowed to perform this action.',
        ));
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($problem) {
            $response = $problem($request, 429, 'rate-limited', 'Too Many Requests', 'Rate limit exceeded.');
            $response->headers->add($e->getHeaders());

            return $response;
        });
        $exceptions->render(fn (ModelNotFoundException $e, Request $request) => $problem(
            $request, 404, 'not-found', 'Not Found', 'Resource not found.',
        ));
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($problem) {
            $status = $e->getStatusCode();
            $title = Response::$statusTexts[$status] ?? 'Error';
            $detail = $status === 404 ? 'Resource not found.' : ($e->getMessage() ?: $title);

            $response = $problem($request, $status, $status === 404 ? 'not-found' : 'http-error', $title, $detail);
            $response->headers->add($e->getHeaders());

            return $response;
        });
        // Anything unexpected: generic 500 without internals (debug mode keeps Laravel's detailed output).
        $exceptions->render(function (Throwable $e, Request $request) use ($problem) {
            if (config('app.debug')) {
                return null;
            }

            return $problem($request, 500, 'internal-error', 'Internal Server Error', 'An unexpected error occurred.');
        });
    })->create();
