<?php

use App\Models\Game;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => Sanctum::actingAs($this->admin = User::factory()->platformAdmin()->create()));

it('creates games with one-time credentials and stores only hashes', function () {
    $res = $this->postJson('/api/v1/admin/games', ['name' => 'Nova', 'slug' => 'nova', 'quota_llm_tokens_monthly' => 1000])
        ->assertCreated()->assertJsonStructure(['data' => ['credentials' => ['api_key', 'signing_secret']]]);

    $game = Game::query()->where('slug', 'nova')->sole();
    expect($game->api_key_hash)->toBe(hash('sha256', $res->json('data.credentials.api_key')))
        ->and($game->getRawOriginal('signing_secret'))->not->toBe($res->json('data.credentials.signing_secret'));

    $this->getJson('/api/v1/admin/games/nova')->assertOk()->assertJsonMissingPath('data.api_key_hash')->assertJsonMissingPath('data.signing_secret');
});

it('rotates keys, adds game admins and audits everything', function () {
    $game = Game::factory()->create();
    $old = $game->rotateCredentials();

    $new = $this->postJson("/api/v1/admin/games/{$game->slug}/rotate-key")->assertOk()->json('data.credentials');
    expect(Game::findByApiKey($old['api_key']))->toBeNull()
        ->and(Game::findByApiKey($new['api_key'])?->id)->toBe($game->id);

    $user = User::factory()->create();
    $this->postJson("/api/v1/admin/games/{$game->slug}/admins", ['email' => $user->email])->assertCreated();
    expect($user->canManage($game))->toBeTrue();

    $this->putJson("/api/v1/admin/games/{$game->slug}", ['quota_daily_xp_per_player' => 42])->assertOk();

    $actions = collect($this->getJson('/api/v1/admin/audit-logs?game_id='.$game->id)->json('data'))->pluck('action')->all();
    expect($actions)->toContain('game.credentials_rotated', 'game.admin_added', 'game.updated');
    $diff = collect($this->getJson('/api/v1/admin/audit-logs?action=game.updated')->json('data'))->first()['diff'];
    expect($diff['quota_daily_xp_per_player'])->toBe(['from' => 5000, 'to' => 42]);
});

it('soft deletes games', function () {
    $game = Game::factory()->create();
    $this->deleteJson("/api/v1/admin/games/{$game->slug}")->assertNoContent();
    expect(Game::withTrashed()->find($game->id)->trashed())->toBeTrue();
});
