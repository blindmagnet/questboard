<?php

use App\Domain\Outbox\Outbox;
use App\Domain\Outbox\OutboxEventPublished;
use App\Domain\Outbox\OutboxPublisher;
use App\Models\Game;
use App\Models\OutboxEvent;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\NullQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

it('never loses an event when the queue is down and publishes it later', function () {
    $game = Game::factory()->create();
    $player = playerIn($game);
    $quest = quest($game);

    // Break the queue: every push throws, like an unreachable Redis.
    app('queue')->extend('broken', fn () => new class implements ConnectorInterface
    {
        public function connect(array $config)
        {
            return new class extends NullQueue
            {
                public function push($job, $data = '', $queue = null)
                {
                    throw new RuntimeException('queue unavailable');
                }

                public function later($delay, $job, $data = '', $queue = null)
                {
                    throw new RuntimeException('queue unavailable');
                }
            };
        }
    });
    config(['queue.connections.broken' => ['driver' => 'broken'], 'queue.default' => 'broken']);

    $this->withHeaders(idem())->postJson("/api/v1/games/{$game->slug}/quests/{$quest->id}/complete", ['score' => 10])
        ->assertCreated(); // the core write path does not depend on the queue

    $event = OutboxEvent::query()->where('type', 'quest.completed')->sole();
    expect($event->status)->toBe('pending')->and($player->refresh()->xp)->toBe(1);

    // The scheduled publisher runs while the queue is still down: the event stays pending.
    $this->artisan('outbox:publish')->assertSuccessful();
    expect($event->refresh()->status)->toBe('pending')
        ->and($event->attempts)->toBe(1)
        ->and($event->last_error)->toContain('queue unavailable');

    // Queue recovers.
    config(['queue.default' => 'sync']);
    $this->travel(5)->minutes();
    $this->artisan('outbox:publish')->assertSuccessful();

    expect($event->refresh()->status)->toBe('published');
    $this->getJson("/api/v1/games/{$game->slug}/leaderboard")->assertJsonPath('data.0.player_id', $player->id);
});

it('does not emit events for rolled back transactions', function () {
    $game = Game::factory()->create();

    try {
        DB::transaction(function () use ($game) {
            app(Outbox::class)->record('quest.completed', $game->id, ['x' => 1]);
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    expect(OutboxEvent::query()->count())->toBe(0);
});

it('marks events failed after max attempts and recovers stuck claims', function () {
    config(['questboard.outbox.max_attempts' => 1]);
    $stuck = app(Outbox::class)->record('season.closed', null, []);
    $stuck->forceFill(['status' => 'publishing'])->save();
    OutboxEvent::query()->whereKey($stuck->id)->update(['updated_at' => now()->subMinutes(10)]);

    Event::listen(OutboxEventPublished::class, fn () => throw new RuntimeException('down'));
    app(OutboxPublisher::class)->publishPending();

    expect($stuck->refresh()->status)->toBe('failed');
});
