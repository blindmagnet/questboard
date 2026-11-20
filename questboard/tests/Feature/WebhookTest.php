<?php

use App\Domain\Outbox\Outbox;
use App\Domain\Webhooks\WebhookDispatcher;
use App\Models\Game;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->game = Game::factory()->create();
    gameAdmin($this->game);
    $this->base = "/api/v1/games/{$this->game->slug}/webhooks";
});

it('rejects non-https and private targets (SSRF)', function (string $url) {
    $this->postJson($this->base, ['url' => $url, 'events' => ['quest.completed']])
        ->assertUnprocessable()->assertJsonStructure(['errors' => ['url']]);
})->with(['http://hooks.example.com/x', 'https://127.0.0.1/x', 'https://10.1.2.3/x', 'https://localhost/x', 'https://169.254.169.254/latest']);

it('delivers signed events once per endpoint and event', function () {
    Http::fake(['hooks.example.com/*' => Http::response('ok', 200)]);
    $created = $this->postJson($this->base, ['url' => 'https://hooks.example.com/qb', 'events' => ['quest.completed']])
        ->assertCreated()->json('data');
    expect($created['secret'])->toStartWith('whsec_');
    $this->getJson($this->base)->assertOk()->assertJsonMissingPath('data.0.secret');

    $player = playerIn($this->game);
    $quest = quest($this->game);
    $this->withHeaders(idem())->postJson("/api/v1/games/{$this->game->slug}/quests/{$quest->id}/complete", ['score' => 5])->assertCreated();

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($created, $player) {
        $ts = $request->header('X-QuestBoard-Timestamp')[0];
        $expected = WebhookDispatcher::signature($created['secret'], $ts, $request->body());

        return $request->header('X-QuestBoard-Signature')[0] === $expected
            && $request['type'] === 'quest.completed'
            && $request['data']['player_id'] === $player->id
            && $request->header('X-QuestBoard-Event-Id')[0] === $request['id'];
    });

    // A redelivered outbox event does not create a second delivery.
    $delivery = WebhookDelivery::query()->sole();
    expect(app(WebhookDispatcher::class)->fanOut($delivery->event_id, 'quest.completed', $this->game->id))->toBe(0)
        ->and($delivery->status)->toBe('delivered');
});

it('retries with backoff and disables an endpoint after consecutive failures', function () {
    config(['questboard.webhooks.disable_after_consecutive_failures' => 2]);
    Http::fake(['*' => Http::response('nope', 500)]);

    $endpoint = new WebhookEndpoint(['url' => 'https://hooks.example.com/fail', 'events' => ['quest.completed']]);
    $endpoint->game_id = $this->game->id;
    $endpoint->secret = 'whsec_test';
    $endpoint->save();

    $dispatcher = app(WebhookDispatcher::class);
    $outbox = app(Outbox::class);

    foreach ([1, 2] as $round) {
        $event = $outbox->record('quest.completed', $this->game->id, ['n' => $round]);
        $delivery = WebhookDelivery::query()->create(['endpoint_id' => $endpoint->id, 'event_id' => $event->id, 'event_type' => 'quest.completed']);

        $delays = [];
        while (($delay = $dispatcher->attempt($delivery->refresh())) !== null) {
            $delays[] = $delay;
        }
        expect($delays)->toBe([10, 30, 90, 270, 810])
            ->and($delivery->refresh()->status)->toBe('failed')
            ->and($delivery->attempts)->toBe(6)
            ->and($delivery->status_code)->toBe(500);
    }

    expect($endpoint->refresh()->is_active)->toBeFalse()->and($endpoint->disabled_at)->not->toBeNull();

    // Re-enabling resets the failure counter.
    $this->putJson("{$this->base}/{$endpoint->id}", ['is_active' => true])->assertOk()->assertJsonPath('data.consecutive_failures', 0);
});

it('lists delivery history', function () {
    Http::fake(['*' => Http::response('', 204)]);
    $id = $this->postJson($this->base, ['url' => 'https://hooks.example.com/qb', 'events' => ['player.flagged']])->json('data.id');

    playerIn($this->game);
    $quest = quest($this->game, ['min_duration_ms' => 60000]);
    $this->withHeaders(idem())->postJson("/api/v1/games/{$this->game->slug}/quests/{$quest->id}/complete", ['score' => 1, 'duration_ms' => 1]);

    gameAdmin($this->game);
    $this->getJson("{$this->base}/{$id}/deliveries")->assertOk()
        ->assertJsonPath('data.0.event_type', 'player.flagged')->assertJsonPath('data.0.status', 'delivered');
});
