<?php

use App\Models\IdempotencyKey;
use App\Models\OutboxEvent;
use Illuminate\Support\Facades\Schedule;

// Safety net for the outbox (the API also triggers publishing right after commit).
Schedule::command('outbox:publish')->everyMinute()->withoutOverlapping();
Schedule::command('seasons:tick')->everyMinute()->withoutOverlapping();
Schedule::command('leaderboard:verify --repair')->hourly()->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Housekeeping.
Schedule::call(fn () => IdempotencyKey::query()->where('expires_at', '<', now())->delete())->hourly()->name('idempotency:prune');
Schedule::call(fn () => OutboxEvent::query()->where('status', OutboxEvent::STATUS_PUBLISHED)
    ->where('published_at', '<', now()->subDays(14))->delete())->daily()->name('outbox:prune');
Schedule::command('sanctum:prune-expired --hours=24')->daily();
