<?php

namespace App\Http\Middleware;

use App\Support\Metrics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordRequestMetrics
{
    public function __construct(private readonly Metrics $metrics) {}

    public function handle(Request $request, Closure $next): Response
    {
        $start = hrtime(true);
        $response = $next($request);

        $route = $request->route()?->getName() ?? 'unmatched';
        $labels = ['route' => $route, 'method' => $request->method()];
        $this->metrics->observe('http_request_duration_seconds', (hrtime(true) - $start) / 1e9, $labels);
        $this->metrics->increment('http_requests_total', $labels + ['status' => (string) $response->getStatusCode()]);

        return $response;
    }
}
