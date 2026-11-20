<?php

namespace App\Domain\Outbox;

use App\Models\OutboxEvent;
use App\Support\Metrics;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

class OutboxPublisher
{
    public function __construct(private readonly Dispatcher $events, private readonly Metrics $metrics) {}

    /** Publishes due events. Returns the number of events published. */
    public function publishPending(int $limit = 100): int
    {
        $published = 0;
        $maxAttempts = (int) config('questboard.outbox.max_attempts');

        // Recover rows claimed by a publisher that crashed mid-flight.
        OutboxEvent::query()
            ->where('status', 'publishing')
            ->where('updated_at', '<', now()->subMinutes(5))
            ->update(['status' => OutboxEvent::STATUS_PENDING]);

        $ids = OutboxEvent::query()
            ->where('status', OutboxEvent::STATUS_PENDING)
            ->where('available_at', '<=', now())
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            // Claim the row atomically so concurrent publishers never double-send.
            $claimed = OutboxEvent::query()
                ->whereKey($id)
                ->where('status', OutboxEvent::STATUS_PENDING)
                ->update(['status' => 'publishing', 'updated_at' => now()]);

            if ($claimed === 0) {
                continue;
            }

            /** @var OutboxEvent $event */
            $event = OutboxEvent::query()->findOrFail($id);

            try {
                $this->events->dispatch(new OutboxEventPublished(
                    $event->id, $event->type, $event->game_id, $event->payload, $event->created_at->toIso8601String(),
                ));

                $event->forceFill([
                    'status' => OutboxEvent::STATUS_PUBLISHED,
                    'published_at' => now(),
                    'attempts' => $event->attempts + 1,
                    'last_error' => null,
                ])->save();

                $published++;
                $this->metrics->increment('outbox_published_total', ['type' => $event->type]);
            } catch (Throwable $e) {
                $attempts = $event->attempts + 1;
                $event->forceFill([
                    'status' => $attempts >= $maxAttempts ? OutboxEvent::STATUS_FAILED : OutboxEvent::STATUS_PENDING,
                    'attempts' => $attempts,
                    'last_error' => mb_substr($e->getMessage(), 0, 1000),
                    'available_at' => now()->addSeconds(min(300, 2 ** $attempts)),
                ])->save();

                $this->metrics->increment('outbox_publish_failures_total', ['type' => $event->type]);
                Log::warning('outbox.publish_failed', ['event_id' => $event->id, 'error' => $e->getMessage()]);
            }
        }

        return $published;
    }
}
