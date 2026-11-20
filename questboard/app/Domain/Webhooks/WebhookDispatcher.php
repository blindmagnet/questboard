<?php

namespace App\Domain\Webhooks;

use App\Domain\AntiCheat\RequestSigner;
use App\Jobs\DeliverWebhookJob;
use App\Models\OutboxEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Metrics;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * At-least-once webhook delivery. Each (endpoint, event) pair is a unique
 * delivery row; consumers must de-duplicate on the event id.
 *
 * Headers:
 *   X-QuestBoard-Event, X-QuestBoard-Event-Id, X-QuestBoard-Timestamp,
 *   X-QuestBoard-Signature: sha256=HMAC_SHA256(secret, "{timestamp}.{body}")
 */
class WebhookDispatcher
{
    public function __construct(private readonly UrlGuard $guard, private readonly Metrics $metrics) {}

    public function fanOut(string $eventId, string $type, int $gameId): int
    {
        $created = 0;
        WebhookEndpoint::withoutGlobalScopes()
            ->where('game_id', $gameId)->where('is_active', true)->get()
            ->filter(fn (WebhookEndpoint $e) => $e->subscribesTo($type))
            ->each(function (WebhookEndpoint $endpoint) use ($eventId, $type, &$created) {
                $delivery = WebhookDelivery::query()->firstOrCreate(
                    ['endpoint_id' => $endpoint->id, 'event_id' => $eventId],
                    ['event_type' => $type, 'status' => 'pending'],
                );
                if ($delivery->wasRecentlyCreated) {
                    DeliverWebhookJob::dispatch($delivery->id);
                    $created++;
                }
            });

        return $created;
    }

    public static function body(OutboxEvent $event): string
    {
        return (string) json_encode([
            'id' => $event->id,
            'type' => $event->type,
            'created_at' => $event->created_at->toIso8601String(),
            'data' => $event->payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function signature(string $secret, string $timestamp, string $body): string
    {
        return 'sha256='.RequestSigner::sign($secret, $timestamp.'.'.$body);
    }

    /**
     * Makes one attempt. Returns the delay in seconds before the next attempt,
     * or null when no retry is needed (delivered, or permanently failed).
     */
    public function attempt(WebhookDelivery $delivery): ?int
    {
        $endpoint = $delivery->endpoint;
        $event = OutboxEvent::query()->find($delivery->event_id);

        if (! $endpoint || ! $event || $delivery->status !== 'pending') {
            return null;
        }
        if (! $endpoint->is_active) {
            $delivery->forceFill(['status' => 'failed', 'last_error' => 'Endpoint disabled.'])->save();

            return null;
        }

        $attempts = $delivery->attempts + 1;
        $body = self::body($event);
        $timestamp = (string) time();
        $status = null;
        $error = null;

        if (! $this->guard->isAllowed($endpoint->url)) {
            $error = 'Target URL is not allowed (non-HTTPS or private address).';
        } else {
            try {
                $response = Http::timeout((int) config('questboard.webhooks.timeout_seconds'))
                    ->withoutRedirecting()
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'User-Agent' => 'QuestBoard-Webhooks/1.0',
                        'X-QuestBoard-Event' => $event->type,
                        'X-QuestBoard-Event-Id' => $event->id,
                        'X-QuestBoard-Timestamp' => $timestamp,
                        'X-QuestBoard-Signature' => self::signature($endpoint->secret, $timestamp, $body),
                    ])
                    ->withBody($body, 'application/json')
                    ->post($endpoint->url);
                $status = $response->status();
                if (! $response->successful()) {
                    $error = "HTTP {$status}";
                }
            } catch (Throwable $e) {
                $error = mb_substr($e->getMessage(), 0, 500);
            }
        }

        if ($error === null) {
            $delivery->forceFill([
                'status' => 'delivered', 'status_code' => $status, 'attempts' => $attempts,
                'delivered_at' => now(), 'next_retry_at' => null, 'last_error' => null,
            ])->save();
            $endpoint->forceFill(['consecutive_failures' => 0])->save();
            $this->metrics->increment('webhook_deliveries_total', ['result' => 'delivered']);

            return null;
        }

        $this->metrics->increment('webhook_deliveries_total', ['result' => 'failed_attempt']);
        $max = (int) config('questboard.webhooks.max_attempts');
        $backoff = config('questboard.webhooks.backoff_seconds');

        if ($attempts >= $max) {
            $delivery->forceFill([
                'status' => 'failed', 'status_code' => $status, 'attempts' => $attempts,
                'last_error' => $error, 'next_retry_at' => null,
            ])->save();

            $failures = $endpoint->consecutive_failures + 1;
            $disable = $failures >= (int) config('questboard.webhooks.disable_after_consecutive_failures');
            $endpoint->forceFill([
                'consecutive_failures' => $failures,
                'is_active' => $disable ? false : $endpoint->is_active,
                'disabled_at' => $disable ? now() : $endpoint->disabled_at,
            ])->save();

            return null;
        }

        $delay = (int) ($backoff[$attempts - 1] ?? end($backoff));
        $delivery->forceFill([
            'status_code' => $status, 'attempts' => $attempts, 'last_error' => $error,
            'next_retry_at' => now()->addSeconds($delay),
        ])->save();

        return $delay;
    }
}
