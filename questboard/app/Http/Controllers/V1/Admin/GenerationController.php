<?php

namespace App\Http\Controllers\V1\Admin;

use App\Domain\Llm\QuestGenerator;
use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateQuestsRequest;
use App\Http\Resources\GenerationResource;
use App\Jobs\GenerateQuestsJob;
use App\Models\Game;
use App\Models\LlmGeneration;
use App\Models\PromptVersion;
use App\Support\ProblemException;
use Illuminate\Http\JsonResponse;

class GenerationController extends Controller
{
    public function store(GenerateQuestsRequest $request, Game $game): JsonResponse
    {
        $used = QuestGenerator::tokensUsedThisMonth($game);
        if ($game->quota_llm_tokens_monthly > 0 && $used >= $game->quota_llm_tokens_monthly) {
            throw new ProblemException(429, 'llm-quota-exceeded', 'Monthly LLM token quota exhausted for this game.', [
                'quota' => $game->quota_llm_tokens_monthly,
                'used' => $used,
            ]);
        }

        $prompt = PromptVersion::active(PromptVersion::QUEST_GENERATOR);
        if (! $prompt) {
            throw new ProblemException(503, 'llm-unavailable', 'No active prompt version is configured.');
        }

        $generation = LlmGeneration::query()->create([
            'game_id' => $game->id,
            'requested_by' => $request->user()->id,
            'prompt_version_id' => $prompt->id,
            'input' => [
                'theme' => $request->string('theme')->toString(),
                'difficulty' => $request->string('difficulty')->toString(),
                'count' => $request->integer('count'),
                'language' => $request->string('language', 'en')->toString(),
            ],
            'status' => LlmGeneration::STATUS_QUEUED,
        ]);

        GenerateQuestsJob::dispatch($generation->id);

        return (new GenerationResource($generation->refresh()))->response()
            ->setStatusCode(202)
            ->header('Location', route('v1.generations.show', [$game, $generation]));
    }

    public function show(Game $game, LlmGeneration $generation): GenerationResource
    {
        return new GenerationResource($generation);
    }

    public function usage(Game $game): JsonResponse
    {
        $month = LlmGeneration::query()->where('created_at', '>=', now()->startOfMonth());
        $used = QuestGenerator::tokensUsedThisMonth($game);

        return response()->json(['data' => [
            'period_start' => now()->startOfMonth()->toIso8601String(),
            'tokens_used' => $used,
            'tokens_quota' => $game->quota_llm_tokens_monthly,
            'quota_used_ratio' => $game->quota_llm_tokens_monthly > 0 ? round($used / $game->quota_llm_tokens_monthly, 4) : null,
            'cost_estimate_usd' => round((float) (clone $month)->sum('cost_estimate'), 6),
            'generations' => (clone $month)->count(),
            'by_status' => (clone $month)->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status'),
        ]]);
    }
}
