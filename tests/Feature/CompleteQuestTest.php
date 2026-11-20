<?php

use App\Models\Game;
use App\Models\IdempotencyKey;
use App\Models\OutboxEvent;
use App\Models\QuestCompletion;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->game = Game::factory()->create();
    $this->player = playerIn($this->game);
    $this->url = fn ($quest) => "/api/v1/games/{$this->game->slug}/quests/{$quest->id}/complete";
});

it('awards xp, updates level and records an outbox event', function () {
    $quest = quest($this->game, ['xp_reward' => 100, 'max_score' => 1000, 'difficulty' => 'hard']);

    $this->withHeaders(idem())->postJson(($this->url)($quest), ['score' => 870, 'duration_ms' => 41200])
        ->assertCreated()
        ->assertJsonPath('data.status', 'accepted')
        ->assertJsonPath('data.xp_awarded', 131)
        ->assertJsonPath('data.player.xp', 131)
        ->assertJsonPath('data.player.level', 2)
        ->assertJsonPath('data.level_up', true);

    expect($this->player->refresh()->xp)->toBe(131)
        ->and(OutboxEvent::query()->where('type', 'quest.completed')->sole()->status)->toBe('published');
});

it('levels up exactly at the boundary', function () {
    $this->player->forceFill(['xp' => 350, 'level' => 2])->save();
    $quest = quest($this->game, ['xp_reward' => 50, 'max_score' => 10]);

    $this->withHeaders(idem())->postJson(($this->url)($quest), ['score' => 10])
        ->assertJsonPath('data.player.xp', 400)->assertJsonPath('data.player.level', 3)->assertJsonPath('data.level_up', true);
});

it('replays the same response for a repeated Idempotency-Key without double xp', function () {
    $quest = quest($this->game, ['is_repeatable' => true]);
    $headers = idem();

    $first = $this->withHeaders($headers)->postJson(($this->url)($quest), ['score' => 500])->assertCreated();
    $second = $this->withHeaders($headers)->postJson(($this->url)($quest), ['score' => 500])
        ->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json())->toBe($first->json())
        ->and($this->player->refresh()->xp)->toBe(50)
        ->and(QuestCompletion::query()->count())->toBe(1);
});

it('rejects a reused Idempotency-Key with a different body', function () {
    $quest = quest($this->game, ['is_repeatable' => true]);
    $headers = idem();

    $this->withHeaders($headers)->postJson(($this->url)($quest), ['score' => 500])->assertCreated();
    $this->withHeaders($headers)->postJson(($this->url)($quest), ['score' => 900])
        ->assertUnprocessable()->assertJsonPath('type', 'https://questboard.dev/problems/idempotency-key-mismatch');
});

it('requires an Idempotency-Key', function () {
    $this->postJson(($this->url)(quest($this->game)), ['score' => 1])
        ->assertStatus(400)->assertJsonPath('type', 'https://questboard.dev/problems/idempotency-key-required');
});

it('falls back to the database when the same key arrives again (e.g. cache lost)', function () {
    $quest = quest($this->game, ['is_repeatable' => true]);
    $headers = idem();

    $this->withHeaders($headers)->postJson(($this->url)($quest), ['score' => 500])->assertCreated();
    IdempotencyKey::query()->delete();

    $this->withHeaders($headers)->postJson(($this->url)($quest), ['score' => 500])->assertOk();
    expect(QuestCompletion::query()->count())->toBe(1)->and($this->player->refresh()->xp)->toBe(50);
});

it('applies a non-repeatable quest only once, even when requests race', function () {
    $quest = quest($this->game);

    $this->withHeaders(idem())->postJson(($this->url)($quest), ['score' => 100])->assertCreated();
    $this->withHeaders(idem())->postJson(($this->url)($quest), ['score' => 100])
        ->assertStatus(409)->assertJsonPath('type', 'https://questboard.dev/problems/quest-already-completed');

    // Simulate the loser of a race: the application check passed but the row already exists.
    expect(fn () => QuestCompletion::query()->create([
        'game_id' => $this->game->id, 'player_id' => $this->player->id, 'quest_id' => $quest->id,
        'once_guard' => $quest->id, 'score' => 1, 'idempotency_key' => 'race-'.uniqid(), 'completed_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);

    expect($this->player->refresh()->xp)->toBe(10);
});

it('rejects a concurrent request holding the same key', function () {
    $quest = quest($this->game);
    $headers = idem();
    $scope = $this->player->user_id.'|POST|'.ltrim(($this->url)($quest), '/');
    $lock = Cache::lock('idem:'.hash('sha256', $scope.$headers['Idempotency-Key']), 10);
    $lock->get();

    $this->withHeaders($headers)->postJson(($this->url)($quest), ['score' => 100])
        ->assertStatus(409)->assertJsonPath('type', 'https://questboard.dev/problems/idempotency-in-progress');

    $lock->release();
});

it('enforces cooldown on repeatable quests', function () {
    $quest = quest($this->game, ['is_repeatable' => true, 'cooldown_seconds' => 3600]);

    $this->withHeaders(idem())->postJson(($this->url)($quest), ['score' => 10])->assertCreated();
    $this->withHeaders(idem())->postJson(($this->url)($quest), ['score' => 10])
        ->assertStatus(429)->assertJsonPath('type', 'https://questboard.dev/problems/cooldown-active');

    $this->travel(3601)->seconds();
    $this->withHeaders(idem())->postJson(($this->url)($quest), ['score' => 10])->assertCreated();
});

it('rejects impossible scores and future timestamps', function () {
    $quest = quest($this->game, ['max_score' => 100]);

    $this->withHeaders(idem())->postJson(($this->url)($quest), ['score' => 101])
        ->assertUnprocessable()->assertJsonPath('max_score', 100);
    $this->withHeaders(idem())->postJson(($this->url)($quest), ['score' => 10, 'completed_at' => now()->addHour()->toIso8601String()])
        ->assertUnprocessable();
    $this->withHeaders(idem())->postJson(($this->url)($quest), ['score' => -1])->assertUnprocessable();

    expect($this->player->refresh()->risk_score)->toBe(20)->and(QuestCompletion::query()->count())->toBe(0);
});

it('holds impossibly fast completions for review without xp', function () {
    $quest = quest($this->game, ['min_duration_ms' => 60000]);

    $this->withHeaders(idem())->postJson(($this->url)($quest), ['score' => 100, 'duration_ms' => 2000])
        ->assertStatus(202)->assertJsonPath('data.status', 'flagged')->assertJsonPath('data.xp_awarded', 0);

    expect($this->player->refresh()->xp)->toBe(0)
        ->and($this->player->risk_score)->toBe(70)
        ->and(OutboxEvent::query()->where('type', 'player.flagged')->exists())->toBeTrue();
});

it('refuses completions from banned players and drafts', function () {
    $this->withHeaders(idem())->postJson(($this->url)(quest($this->game, ['is_active' => false, 'published_at' => null])), ['score' => 1])
        ->assertUnprocessable()->assertJsonPath('type', 'https://questboard.dev/problems/quest-not-available');

    $this->player->forceFill(['banned_at' => now()])->save();
    $this->withHeaders(idem())->postJson(($this->url)(quest($this->game)), ['score' => 1])
        ->assertForbidden()->assertJsonPath('type', 'https://questboard.dev/problems/player-banned');
});

it('applies the per-game daily xp cap', function () {
    $this->game->update(['quota_daily_xp_per_player' => 150]);
    $quest = quest($this->game, ['is_repeatable' => true, 'xp_reward' => 100, 'max_score' => 10]);

    $this->withHeaders(idem())->postJson(($this->url)($quest), ['score' => 10])->assertJsonPath('data.xp_awarded', 100);
    $this->withHeaders(idem())->postJson(($this->url)($quest), ['score' => 10])->assertJsonPath('data.xp_awarded', 50);
    $this->withHeaders(idem())->postJson(($this->url)($quest), ['score' => 10])->assertJsonPath('data.xp_awarded', 0);
});

it('lets a game admin approve or reject flagged completions', function () {
    $quest = quest($this->game, ['min_duration_ms' => 60000, 'is_repeatable' => true]);
    $this->withHeaders(idem())->postJson(($this->url)($quest), ['score' => 100, 'duration_ms' => 10])->assertStatus(202);
    $this->withHeaders(idem())->postJson(($this->url)($quest), ['score' => 100, 'duration_ms' => 10])->assertStatus(202);
    [$a, $b] = QuestCompletion::query()->orderBy('id')->get();

    gameAdmin($this->game);
    $this->getJson("/api/v1/games/{$this->game->slug}/flags")->assertOk()->assertJsonCount(2, 'data.completions');
    $this->postJson("/api/v1/games/{$this->game->slug}/completions/{$a->id}/approve")->assertOk()->assertJsonPath('data.status', 'accepted');
    $this->postJson("/api/v1/games/{$this->game->slug}/completions/{$b->id}/reject")->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->postJson("/api/v1/games/{$this->game->slug}/completions/{$b->id}/approve")->assertStatus(409);

    expect($this->player->refresh()->xp)->toBe(10);
    $this->assertDatabaseHas('audit_logs', ['action' => 'completion.approved', 'subject_id' => $a->id]);
});
