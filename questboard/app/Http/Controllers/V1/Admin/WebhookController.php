<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\WebhookRequest;
use App\Http\Resources\WebhookResource;
use App\Models\Game;
use App\Models\WebhookEndpoint;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Game $game): AnonymousResourceCollection
    {
        return WebhookResource::collection(WebhookEndpoint::query()->orderBy('id')->get());
    }

    public function store(WebhookRequest $request, Game $game): JsonResponse
    {
        $endpoint = new WebhookEndpoint($request->validated());
        $endpoint->game_id = $game->id;
        $endpoint->secret = 'whsec_'.Str::random(40);
        $endpoint->save();

        $this->audit->log('webhook.created', $endpoint, ['url' => $endpoint->url, 'events' => $endpoint->events]);

        // The signing secret is only ever shown once.
        return response()->json(['data' => [
            ...(new WebhookResource($endpoint))->resolve($request),
            'secret' => $endpoint->secret,
        ]], 201);
    }

    public function update(WebhookRequest $request, Game $game, WebhookEndpoint $webhook): WebhookResource
    {
        $webhook->fill($request->validated());
        if ($webhook->isDirty('is_active') && $webhook->is_active) {
            $webhook->forceFill(['consecutive_failures' => 0, 'disabled_at' => null]);
        }
        $diff = AuditLogger::diff($webhook);
        $webhook->save();
        $this->audit->log('webhook.updated', $webhook, $diff);

        return new WebhookResource($webhook);
    }

    public function destroy(Game $game, WebhookEndpoint $webhook): Response
    {
        $webhook->delete();
        $this->audit->log('webhook.deleted', $webhook);

        return response()->noContent();
    }

    public function deliveries(Game $game, WebhookEndpoint $webhook): JsonResponse
    {
        return response()->json(['data' => $webhook->deliveries()->latest('id')->limit(100)->get([
            'id', 'event_id', 'event_type', 'status', 'status_code', 'attempts', 'last_error', 'next_retry_at', 'delivered_at', 'created_at',
        ])]);
    }
}
