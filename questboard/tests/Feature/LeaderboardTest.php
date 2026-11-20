<?php

use App\Domain\Leaderboard\LeaderboardScope;
use App\Domain\Leaderboard\RedisLeaderboard;
use App\Domain\Outbox\OutboxEventPublished;
use App\Listeners\ProjectLeaderboard;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\OutboxEvent;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;

function completeAs(GamePlayer $player, $quest, int $score): void
{
    Sanctum::actingAs($player->user);
    test()->withHeaders(idem())->postJson("/api/v1/games/{$player->game->slug}/quests/{$quest->id}/complete", ['score' => $score])
        ->assertSuccessful();
}

beforeEach(function () {
    $this->game = Game::factory()->create();
    $this->quest = quest($this->game, ['is_repeatable' => true, 'xp_reward' => 100, 'max_score' => 100]);
    $this->players = GamePlayer::factory()->count(5)->create(['game_id' => $this->game->id]);
    foreach ($this->players as $i => $p) {
        completeAs($p, $this->quest, ($i + 1) * 20); // 20,40,...,100 xp
    }
});

it('ranks players from redis with cursor pagination', function () {
    Sanctum::actingAs($this->players[0]->user);

    $page1 = $this->getJson("/api/v1/games/{$this->game->slug}/leaderboard?limit=2")->assertOk();
    expect(collect($page1->json('data'))->pluck('score')->all())->toBe([100, 80])
        ->and($page1->json('data.0.rank'))->toBe(1)
        ->and($page1->json('data.0.name'))->toBe($this->players[4]->user->name)
        ->and($page1->json('meta.total'))->toBe(5);

    $page2 = $this->getJson("/api/v1/games/{$this->game->slug}/leaderboard?limit=2&cursor=".$page1->json('meta.next_cursor'));
    expect(collect($page2->json('data'))->pluck('rank')->all())->toBe([3, 4]);

    $this->getJson("/api/v1/games/{$this->game->slug}/leaderboard?cursor=garbage")->assertStatus(400);
});

it('returns my rank and neighbours', function () {
    Sanctum::actingAs($this->players[2]->user);

    $res = $this->getJson("/api/v1/games/{$this->game->slug}/leaderboard/me")->assertOk();
    expect($res->json('data.rank'))->toBe(3)
        ->and($res->json('data.score'))->toBe(60)
        ->and(collect($res->json('data.neighbours'))->pluck('rank')->all())->toBe([1, 2, 3, 4, 5]);
});

it('maintains a weekly board', function () {
    Sanctum::actingAs($this->players[0]->user);
    $this->getJson("/api/v1/games/{$this->game->slug}/leaderboard?scope=weekly")->assertJsonPath('meta.total', 5);
});

it('rebuilds from mysql and matches the source of truth', function () {
    $lb = app(RedisLeaderboard::class);
    $before = $lb->top($lb->key($this->game->id, LeaderboardScope::All), 0, 10);

    Redis::connection()->flushdb();
    $this->artisan('leaderboard:rebuild', ['game' => $this->game->slug])->assertSuccessful();

    expect($lb->top($lb->key($this->game->id, LeaderboardScope::All), 0, 10))->toBe($before)
        ->and($lb->verifySample($this->game))->toBe([]);
});

it('detects drift and repairs it', function () {
    $lb = app(RedisLeaderboard::class);
    Redis::connection()->zincrby($lb->key($this->game->id, LeaderboardScope::All), 999, (string) $this->players[0]->id);

    $this->artisan('leaderboard:verify --repair')->assertFailed();
    expect($lb->verifySample($this->game))->toBe([]);
});

it('does not double count when the outbox redelivers an event', function () {
    $lb = app(RedisLeaderboard::class);
    $event = OutboxEvent::query()->where('type', 'quest.completed')->first();
    $listener = app(ProjectLeaderboard::class);
    $listener->handle(new OutboxEventPublished($event->id, $event->type, $event->game_id, $event->payload, (string) $event->created_at));

    expect($lb->verifySample($this->game))->toBe([]);
});

it('excludes banned and shadow-banned players', function () {
    gameAdmin($this->game);
    $this->postJson("/api/v1/games/{$this->game->slug}/players/{$this->players[4]->id}/ban", ['shadow' => true, 'reason' => 'bot'])
        ->assertOk()->assertJsonPath('data.ban_reason', 'bot');

    completeAs($this->players[4], $this->quest, 100); // shadow-banned: accepted but not on the board
    Sanctum::actingAs($this->players[0]->user);

    $res = $this->getJson("/api/v1/games/{$this->game->slug}/leaderboard")->assertOk();
    expect(collect($res->json('data'))->pluck('player_id'))->not->toContain($this->players[4]->id)
        ->and($res->json('meta.total'))->toBe(4);
    $this->assertDatabaseHas('audit_logs', ['action' => 'player.shadow_banned']);
});

it('restores an unbanned player on the leaderboard', function () {
    gameAdmin($this->game);
    $target = $this->players[4];
    $this->postJson("/api/v1/games/{$this->game->slug}/players/{$target->id}/ban")->assertOk();
    $this->getJson("/api/v1/games/{$this->game->slug}/leaderboard")->assertJsonPath('meta.total', 4);

    $this->deleteJson("/api/v1/games/{$this->game->slug}/players/{$target->id}/ban")->assertOk();
    $this->getJson("/api/v1/games/{$this->game->slug}/leaderboard")
        ->assertJsonPath('meta.total', 5)->assertJsonPath('data.0.player_id', $target->id);
});
