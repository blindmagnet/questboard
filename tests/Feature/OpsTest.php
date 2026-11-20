<?php

use App\Domain\Outbox\Outbox;
use App\Models\Game;
use Illuminate\Support\Facades\Route;

it('exposes liveness and readiness', function () {
    $this->getJson('/health/live')->assertOk()->assertJson(['status' => 'ok']);
    $this->getJson('/health/ready')->assertOk()->assertJsonPath('checks.database.ok', true)->assertJsonPath('checks.redis.ok', true);
});

it('reports degraded readiness when the outbox is backed up', function () {
    app(Outbox::class)->record('season.closed', null, []);
    $this->travel(10)->minutes();

    $this->getJson('/health/ready')->assertStatus(503)->assertJsonPath('checks.queue.ok', false);
});

it('serves prometheus metrics to private networks or with a token', function () {
    $this->getJson('/health/live');
    $this->get('/metrics')->assertOk()->assertSee('questboard_http_requests_total', false)->assertSee('questboard_outbox_pending', false);

    config(['questboard.metrics.token' => 'top-secret']);
    $this->get('/metrics')->assertForbidden();
    $this->get('/metrics', ['Authorization' => 'Bearer top-secret'])->assertOk();

    config(['questboard.metrics.token' => null]);
    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get('/metrics')->assertForbidden();
});

it('adds correlation ids and security headers, and renders problem+json', function () {
    $res = $this->getJson('/api/v1/nope', ['X-Request-Id' => 'abcdef123456']);
    $res->assertNotFound()
        ->assertHeader('X-Request-Id', 'abcdef123456')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 404)
        ->assertJsonPath('request_id', 'abcdef123456');

    expect($this->getJson('/health/live', ['X-Request-Id' => 'bad id!'])->headers->get('X-Request-Id'))->not->toBe('bad id!');
});

it('lists active games for authenticated users', function () {
    Game::factory()->count(2)->create();
    Game::factory()->create(['is_active' => false]);
    playerIn(Game::factory()->create());

    $this->getJson('/api/v1/games')->assertOk()->assertJsonCount(3, 'data')->assertJsonMissingPath('data.0.api_key_prefix');
});

it('hides internals of unexpected errors when debug is off', function () {
    config(['app.debug' => false]);
    Route::get('/api/v1/__boom', fn () => throw new RuntimeException('secret db password in message'));

    $this->getJson('/api/v1/__boom')->assertStatus(500)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://questboard.dev/problems/internal-error')
        ->assertDontSee('secret db password');
});
