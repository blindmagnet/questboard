<?php

namespace App\Http\Controllers;

use App\Models\OutboxEvent;
use App\Support\Metrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

class OpsController extends Controller
{
    private const PRIVATE_NETWORKS = ['127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '::1/128', 'fc00::/7'];

    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'redis' => $this->check(fn () => Redis::connection()->ping()),
            'queue' => $this->check(function () {
                $oldest = OutboxEvent::query()->where('status', OutboxEvent::STATUS_PENDING)->min('created_at');
                if ($oldest && now()->diffInSeconds($oldest, true) > 300) {
                    throw new \RuntimeException('Outbox backlog older than 5 minutes.');
                }
            }),
        ];

        $ok = ! in_array(false, array_column($checks, 'ok'), true);

        return response()->json(['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks], $ok ? 200 : 503);
    }

    public function metrics(Request $request, Metrics $metrics): Response
    {
        $token = config('questboard.metrics.token');
        // With METRICS_TOKEN set a bearer token is mandatory; otherwise only private networks may scrape.
        $authorized = $token
            ? hash_equals((string) $token, (string) $request->bearerToken())
            : IpUtils::checkIp((string) $request->ip(), self::PRIVATE_NETWORKS);
        abort_unless($authorized, 403);

        $gauges = [];
        try {
            $gauges['outbox_pending'] = OutboxEvent::query()->where('status', OutboxEvent::STATUS_PENDING)->count();
            $gauges['outbox_failed'] = OutboxEvent::query()->where('status', OutboxEvent::STATUS_FAILED)->count();
            foreach (['default', 'projections', 'webhooks', 'llm'] as $queue) {
                $gauges['queue_size{queue="'.$queue.'"}'] = (int) app('queue')->size($queue);
            }
        } catch (Throwable) {
            // Partial metrics are better than none.
        }

        return response($metrics->render($gauges), 200, ['Content-Type' => 'text/plain; version=0.0.4']);
    }

    /** @return array{ok: bool, error?: string} */
    private function check(callable $fn): array
    {
        try {
            $fn();

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
