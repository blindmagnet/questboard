<?php

use App\Models\Game;
use App\Models\OutboxEvent;
use App\Models\Season;
use App\Models\SeasonScore;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->game = Game::factory()->create();
    $this->admin = gameAdmin($this->game);
    $this->base = "/api/v1/games/{$this->game->slug}/seasons";
});

it('allows only one season at a time', function () {
    $this->postJson($this->base, ['name' => 'S1', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(10)])
        ->assertCreated()->assertJsonPath('data.status', 'active');
    $this->postJson($this->base, ['name' => 'S2', 'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(20)])
        ->assertStatus(409);
    $this->postJson($this->base, ['name' => 'S2', 'starts_at' => now()->addDays(10), 'ends_at' => now()->addDays(20)])
        ->assertCreated()->assertJsonPath('data.status', 'scheduled');
    $this->postJson($this->base, ['name' => 'bad', 'starts_at' => now()->addDays(40), 'ends_at' => now()->addDays(30)])
        ->assertUnprocessable();
});

it('credits season points, then freezes a snapshot when closed', function () {
    $season = Season::query()->find($this->postJson($this->base, [
        'name' => 'S1', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(10),
    ])->json('data.id'));
    $quest = quest($this->game, ['is_repeatable' => true, 'xp_reward' => 100, 'max_score' => 10]);

    $p1 = playerIn($this->game);
    $this->withHeaders(idem())->postJson("/api/v1/games/{$this->game->slug}/quests/{$quest->id}/complete", ['score' => 10]);
    $p2 = playerIn($this->game);
    $this->withHeaders(idem())->postJson("/api/v1/games/{$this->game->slug}/quests/{$quest->id}/complete", ['score' => 5]);

    $this->getJson("/api/v1/games/{$this->game->slug}/leaderboard?scope=season")
        ->assertJsonPath('data.0.player_id', $p1->id)->assertJsonPath('data.0.score', 100);

    Sanctum::actingAs($this->admin);
    $this->postJson("{$this->base}/{$season->id}/close")->assertOk()->assertJsonPath('data.status', 'closed');
    $this->putJson("{$this->base}/{$season->id}", ['name' => 'rename'])->assertStatus(409);

    expect(SeasonScore::query()->where('season_id', $season->id)->orderBy('final_rank')->pluck('player_id')->all())->toBe([$p1->id, $p2->id])
        ->and(OutboxEvent::query()->where('type', 'season.closed')->sole()->payload['participants'])->toBe(2);

    // Later completions no longer change the frozen season.
    Sanctum::actingAs($p2->user);
    $this->travel(1)->hours();
    $this->withHeaders(idem())->postJson("/api/v1/games/{$this->game->slug}/quests/{$quest->id}/complete", ['score' => 10])->assertCreated();
    $this->getJson("/api/v1/games/{$this->game->slug}/leaderboard?scope=season&season_id={$season->id}")
        ->assertJsonPath('data.0.player_id', $p1->id)->assertJsonPath('data.1.score', 50)->assertJsonPath('meta.total', 2);
});

it('closes expired seasons on schedule and auto-renews', function () {
    $this->postJson($this->base, [
        'name' => 'Weekly', 'starts_at' => now()->subDay(), 'ends_at' => now()->addHour(), 'auto_renew' => true, 'renew_days' => 7,
    ])->assertCreated();

    $this->travel(2)->hours();
    $this->artisan('seasons:tick')->assertSuccessful();

    expect(Season::query()->where('status', 'closed')->count())->toBe(1)
        ->and(Season::query()->where('status', 'active')->sole()->name)->toBe('Weekly (next)');
});

it('re-checks overlap and date order when a season is updated', function () {
    $this->postJson($this->base, ['name' => 'S1', 'starts_at' => now()->addDays(1), 'ends_at' => now()->addDays(10)])->assertCreated();
    $s2 = $this->postJson($this->base, ['name' => 'S2', 'starts_at' => now()->addDays(20), 'ends_at' => now()->addDays(30)])->json('data.id');

    $this->putJson("{$this->base}/{$s2}", ['starts_at' => now()->addDays(5)->toIso8601String()])->assertStatus(409);
    $this->putJson("{$this->base}/{$s2}", ['ends_at' => now()->addDays(15)->toIso8601String()])->assertUnprocessable();
    $this->putJson("{$this->base}/{$s2}", ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.name', 'Renamed');
});
