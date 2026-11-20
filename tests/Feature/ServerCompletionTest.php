<?php

use App\Domain\AntiCheat\RequestSigner;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\QuestCompletion;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->game = Game::factory()->create();
    $this->creds = $this->game->rotateCredentials();
    $this->player = GamePlayer::factory()->create(['game_id' => $this->game->id]);
    $this->quest = quest($this->game, ['min_duration_ms' => 60000, 'is_repeatable' => true]);
    $this->path = "/api/v1/server/games/{$this->game->slug}/players/{$this->player->id}/complete";
});

function signed(array $creds, string $path, array $body, ?int $ts = null, ?string $secret = null): array
{
    $ts ??= time();
    $json = json_encode($body);

    return [$json, [
        'X-Api-Key' => $creds['api_key'],
        'X-Timestamp' => (string) $ts,
        'X-Signature' => RequestSigner::sign($secret ?? $creds['signing_secret'], RequestSigner::canonical((string) $ts, 'POST', $path, $json)),
        'Idempotency-Key' => (string) Str::uuid(),
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ]];
}

it('accepts signed server completions (trusted more than clients)', function () {
    [$json, $headers] = signed($this->creds, $this->path, ['quest_id' => $this->quest->id, 'score' => 50, 'duration_ms' => 40000]);

    $this->call('POST', $this->path, [], [], [], $this->transformHeadersToServerVars($headers), $json)
        ->assertCreated()->assertJsonPath('data.status', 'accepted');

    expect($this->player->refresh()->xp)->toBe(5)
        ->and(QuestCompletion::query()->sole()->reported_by)->toBe('server');
});

it('rejects bad signatures, stale timestamps, replays and foreign keys', function () {
    $body = ['quest_id' => $this->quest->id, 'score' => 50, 'duration_ms' => 40000];
    $send = fn ($json, $headers) => $this->call('POST', $this->path, [], [], [], $this->transformHeadersToServerVars($headers), $json);

    [$json, $headers] = signed($this->creds, $this->path, $body, secret: 'wrong');
    $send($json, $headers)->assertUnauthorized()->assertJsonPath('type', 'https://questboard.dev/problems/invalid-signature');

    [$json, $headers] = signed($this->creds, $this->path, $body, ts: time() - 3600);
    $send($json, $headers)->assertUnauthorized()->assertJsonPath('type', 'https://questboard.dev/problems/stale-request');

    [$json, $headers] = signed($this->creds, $this->path, $body);
    $send($json, $headers)->assertCreated();
    $headers['Idempotency-Key'] = (string) Str::uuid();
    $send($json, $headers)->assertUnauthorized()->assertJsonPath('type', 'https://questboard.dev/problems/replayed-request');

    $other = Game::factory()->create();
    [$json, $headers] = signed($other->rotateCredentials(), $this->path, $body);
    $send($json, $headers)->assertUnauthorized()->assertJsonPath('type', 'https://questboard.dev/problems/invalid-api-key');

    // Tampered body with the original signature.
    [$json, $headers] = signed($this->creds, $this->path, $body);
    $send(str_replace('"score":50', '"score":99', $json), $headers)->assertUnauthorized();
});

it('stops accepting a key after rotation', function () {
    $this->game->rotateCredentials();
    [$json, $headers] = signed($this->creds, $this->path, ['quest_id' => $this->quest->id, 'score' => 1, 'duration_ms' => 40000]);

    $this->call('POST', $this->path, [], [], [], $this->transformHeadersToServerVars($headers), $json)->assertUnauthorized();
});
