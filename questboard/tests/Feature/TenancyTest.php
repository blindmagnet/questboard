<?php

use App\Models\Game;
use App\Models\GamePlayer;

beforeEach(function () {
    $this->gameA = Game::factory()->create();
    $this->gameB = Game::factory()->create();
    $this->questB = quest($this->gameB);
});

it('requires joining a game before playing', function () {
    $player = playerIn($this->gameA);

    $this->getJson("/api/v1/games/{$this->gameB->slug}/quests")
        ->assertForbidden()->assertJsonPath('type', 'https://questboard.dev/problems/not-a-member');

    $this->postJson("/api/v1/games/{$this->gameB->slug}/join")->assertCreated();
    $this->getJson("/api/v1/games/{$this->gameB->slug}/quests")->assertOk();
    expect(GamePlayer::withoutGlobalScopes()->where('user_id', $player->user_id)->count())->toBe(2);
});

it('does not resolve another tenant\'s resources through a game URL', function () {
    playerIn($this->gameA);

    $this->getJson("/api/v1/games/{$this->gameA->slug}/quests/{$this->questB->id}")->assertNotFound();
    $this->withHeaders(idem())->postJson("/api/v1/games/{$this->gameA->slug}/quests/{$this->questB->id}/complete", ['score' => 1])
        ->assertNotFound();
});

it('only lists quests of the current game', function () {
    playerIn($this->gameA);
    $mine = quest($this->gameA);

    $ids = collect($this->getJson("/api/v1/games/{$this->gameA->slug}/quests")->json('data'))->pluck('id');
    expect($ids->all())->toBe([$mine->id]);
});

it('prevents a game admin from managing another game', function () {
    gameAdmin($this->gameA);

    $this->postJson("/api/v1/games/{$this->gameA->slug}/quests", ['title' => 'Mine', 'difficulty' => 'easy', 'xp_reward' => 10])->assertCreated();
    $this->postJson("/api/v1/games/{$this->gameB->slug}/quests", ['title' => 'Theirs', 'difficulty' => 'easy', 'xp_reward' => 10])->assertForbidden();
    $this->deleteJson("/api/v1/games/{$this->gameB->slug}/quests/{$this->questB->id}")->assertForbidden();
    $this->getJson("/api/v1/games/{$this->gameB->slug}/flags")->assertForbidden();

    $playerB = GamePlayer::factory()->create(['game_id' => $this->gameB->id]);
    $this->postJson("/api/v1/games/{$this->gameA->slug}/players/{$playerB->id}/ban")->assertNotFound();
});

it('blocks players from admin endpoints and platform admin endpoints', function () {
    playerIn($this->gameA);

    $this->postJson("/api/v1/games/{$this->gameA->slug}/quests/generate", ['theme' => 'x', 'difficulty' => 'easy', 'count' => 1])->assertForbidden();
    $this->getJson('/api/v1/admin/games')->assertForbidden();
});

it('hides inactive games', function () {
    playerIn($this->gameA);
    $this->gameA->update(['is_active' => false]);

    $this->getJson("/api/v1/games/{$this->gameA->slug}/quests")->assertNotFound();
});

it('publishes a draft when an admin activates it via update', function () {
    gameAdmin($this->gameA);
    $draft = quest($this->gameA, ['is_active' => false, 'published_at' => null]);

    $this->putJson("/api/v1/games/{$this->gameA->slug}/quests/{$draft->id}", ['is_active' => true])
        ->assertOk()->assertJsonPath('data.is_active', true);
    expect($draft->refresh()->isPlayable())->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'quest.updated', 'subject_id' => $draft->id]);
});
