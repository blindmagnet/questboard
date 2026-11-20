<?php

namespace App\Jobs;

use App\Domain\Outbox\OutboxPublisher;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** Low-latency trigger for the outbox; the scheduler also runs it every minute as a safety net. */
class PublishOutboxJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 5;

    /**
     * Best-effort, low-latency trigger. A queue outage must never fail the caller:
     * events stay in the outbox and the scheduled `outbox:publish` picks them up.
     */
    public static function trigger(bool $afterCommit = false): void
    {
        try {
            $pending = self::dispatch();
            if ($afterCommit) {
                $pending->afterCommit();
            }
            unset($pending); // dispatches now, inside the try block
        } catch (\Throwable $e) {
            Log::warning('outbox.trigger_failed', ['error' => $e->getMessage()]);
        }
    }

    public function handle(OutboxPublisher $publisher): void
    {
        $publisher->publishPending((int) config('questboard.outbox.batch_size'));
    }
}
