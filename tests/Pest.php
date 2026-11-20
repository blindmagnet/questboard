<?php

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Quest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => Redis::connection()->flushdb())
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/** Creates a game, a user with a player profile in it, and authenticates as that user. */
function playerIn(?Game $game = null): GamePlayer
{
    $game ??= Game::factory()->create();
    $player = GamePlayer::factory()->create(['game_id' => $game->id]);
    Sanctum::actingAs($player->user);

    return $player;
}

function gameAdmin(Game $game): User
{
    $user = User::factory()->create();
    $game->admins()->attach($user->id);
    Sanctum::actingAs($user);

    return $user;
}

function quest(Game $game, array $attributes = []): Quest
{
    return Quest::factory()->create(['game_id' => $game->id] + $attributes);
}

function idem(): array
{
    return ['Idempotency-Key' => (string) Str::uuid()];
}
