<?php

use App\Domain\Achievements\AchievementEvaluator;
use App\Models\Achievement;
use App\Models\Game;
use App\Models\OutboxEvent;
use App\Models\QuestCompletion;

beforeEach(function () {
    $this->game = Game::factory()->create();
    $this->player = playerIn($this->game);
    $make = function (string $key, array $rule) {
        $a = new Achievement(['key' => $key, 'name' => ucfirst($key), 'rule' => $rule]);
        $a->game_id = $this->game->id;
        $a->save();
    };
    $make('first', ['type' => 'completions_count', 'gte' => 1]);
    $make('two_hard', ['type' => 'completions_count', 'difficulty' => 'hard', 'gte' => 2]);
    $make('level3', ['type' => 'level_reached', 'gte' => 3]);
    $this->quest = quest($this->game, ['is_repeatable' => true, 'difficulty' => 'hard', 'xp_reward' => 100, 'max_score' => 10]);
});

it('unlocks achievements asynchronously and exactly once', function () {
    $url = "/api/v1/games/{$this->game->slug}/quests/{$this->quest->id}/complete";
    $this->withHeaders(idem())->postJson($url, ['score' => 10])->assertCreated();

    $keys = fn () => collect($this->getJson("/api/v1/games/{$this->game->slug}/players/me")->json('data.achievements'))->pluck('key')->sort()->values()->all();
    expect($keys())->toBe(['first']);

    $this->withHeaders(idem())->postJson($url, ['score' => 10])->assertCreated(); // 300 xp -> level 2
    $this->withHeaders(idem())->postJson($url, ['score' => 10])->assertCreated(); // 450 xp -> level 3
    expect($keys())->toBe(['first', 'level3', 'two_hard']);

    // Re-evaluating never unlocks twice.
    expect(app(AchievementEvaluator::class)->evaluate($this->player->refresh()))->toBe([])
        ->and(OutboxEvent::query()->where('type', 'achievement.unlocked')->count())->toBe(3)
        ->and(OutboxEvent::query()->where('type', 'achievement.unlocked')->where('status', 'pending')->count())->toBe(0);
});

it('evaluates streak rules', function () {
    $evaluator = app(AchievementEvaluator::class);
    foreach (range(0, 6) as $daysAgo) {
        QuestCompletion::query()->create([
            'game_id' => $this->game->id, 'player_id' => $this->player->id, 'quest_id' => $this->quest->id,
            'score' => 1, 'idempotency_key' => "streak-{$daysAgo}-xx", 'completed_at' => now()->subDays($daysAgo),
        ]);
    }
    expect($evaluator->satisfies($this->player, ['type' => 'streak_days', 'gte' => 7]))->toBeTrue()
        ->and($evaluator->satisfies($this->player, ['type' => 'streak_days', 'gte' => 8]))->toBeFalse();
});

it('lets admins manage achievements with validation and supports ETags', function () {
    gameAdmin($this->game);
    $base = "/api/v1/games/{$this->game->slug}/achievements";

    $this->postJson($base, ['key' => 'bad key', 'name' => 'x', 'rule' => ['type' => 'nope', 'gte' => 1]])
        ->assertUnprocessable()->assertJsonStructure(['errors' => ['key', 'rule.type']]);
    $this->postJson($base, ['key' => 'xp_1k', 'name' => 'XP 1k', 'rule' => ['type' => 'xp_total', 'gte' => 1000]])->assertCreated();
    $this->postJson($base, ['key' => 'xp_1k', 'name' => 'dup', 'rule' => ['type' => 'xp_total', 'gte' => 1]])->assertUnprocessable();

    $etag = $this->getJson($base)->assertOk()->headers->get('ETag');
    $this->getJson($base, ['If-None-Match' => $etag])->assertStatus(304);
});
