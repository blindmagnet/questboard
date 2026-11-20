<?php

namespace App\Domain\Llm;

use App\Models\Game;
use App\Models\LlmGeneration;
use App\Models\PromptVersion;
use App\Models\Quest;
use App\Support\Metrics;
use Illuminate\Support\Facades\DB;

class QuestGenerator
{
    public function __construct(
        private readonly LlmClient $client,
        private readonly PromptBuilder $prompts,
        private readonly QuestSchema $schema,
        private readonly Moderation $moderation,
        private readonly Metrics $metrics,
    ) {}

    /** Tokens used by the game in the current calendar month. */
    public static function tokensUsedThisMonth(Game $game): int
    {
        return (int) LlmGeneration::withoutGlobalScopes()
            ->where('game_id', $game->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum(DB::raw('tokens_in + tokens_out'));
    }

    public function run(LlmGeneration $generation): void
    {
        if (! in_array($generation->status, [LlmGeneration::STATUS_QUEUED, LlmGeneration::STATUS_RUNNING], true)) {
            return;
        }

        $prompt = $generation->promptVersion ?? PromptVersion::active(PromptVersion::QUEST_GENERATOR);
        if (! $prompt) {
            $this->fail($generation, 'No active prompt version.');

            return;
        }

        $generation->forceFill(['status' => LlmGeneration::STATUS_RUNNING, 'prompt_version_id' => $prompt->id])->save();
        $count = (int) $generation->input['count'];
        $messages = $this->prompts->build($prompt, $generation->input);

        $parsed = null;
        $tokensIn = 0;
        $tokensOut = 0;
        $attempts = 0;
        $lastContent = '';

        // One initial call + one corrective retry when the output breaks the schema.
        while ($attempts < 2) {
            $attempts++;
            try {
                $response = $this->client->complete($messages);
            } catch (LlmException $e) {
                $this->metrics->increment('llm_errors_total', ['kind' => 'provider']);
                $generation->forceFill(['attempts' => $attempts, 'tokens_in' => $tokensIn, 'tokens_out' => $tokensOut])->save();
                $this->fail($generation, $e->getMessage());

                return;
            }

            $tokensIn += $response->tokensIn;
            $tokensOut += $response->tokensOut;
            $lastContent = $response->content;
            $parsed = $this->schema->parse($response->content, $count);

            if ($parsed['valid']) {
                break;
            }

            $this->metrics->increment('llm_errors_total', ['kind' => 'schema']);
            $messages = $this->prompts->withCorrection($messages, $response->content, $parsed['errors']);
        }

        $cost = $tokensIn / 1000 * (float) config('questboard.llm.price_per_1k_input')
            + $tokensOut / 1000 * (float) config('questboard.llm.price_per_1k_output');
        $generation->forceFill([
            'attempts' => $attempts,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'cost_estimate' => round($cost, 6),
        ])->save();
        $this->metrics->increment('llm_tokens_total', [], $tokensIn + $tokensOut);
        $this->metrics->increment('llm_cost_usd_total', [], $cost);

        if (! $parsed || ! $parsed['valid']) {
            $generation->forceFill(['output' => ['raw' => mb_substr($lastContent, 0, 4000), 'errors' => $parsed['errors'] ?? []]])->save();
            $this->fail($generation, 'Model output did not match the schema after a corrective retry.');

            return;
        }

        $moderation = $this->moderation->check($parsed['quests']);
        if (! $moderation['passed']) {
            $generation->forceFill([
                'status' => LlmGeneration::STATUS_REJECTED,
                'output' => ['quests' => $parsed['quests']],
                'moderation_result' => $moderation,
                'error' => 'Generated content failed moderation.',
            ])->save();

            return;
        }

        DB::transaction(function () use ($generation, $parsed, $moderation, $prompt) {
            $ids = [];
            foreach ($parsed['quests'] as $data) {
                $quest = new Quest([
                    'title' => $data['title'],
                    'description' => $data['description'],
                    'difficulty' => $data['difficulty'],
                    'xp_reward' => $data['xp_reward'],
                    'max_score' => $data['max_score'],
                    'min_duration_ms' => $data['min_duration_ms'] ?? 0,
                    'is_repeatable' => false,
                    'is_active' => false, // drafts: an admin must review and publish
                ]);
                $quest->game_id = $generation->game_id;
                $quest->source = 'ai';
                $quest->prompt_version_id = $prompt->id;
                $quest->llm_generation_id = $generation->id;
                $quest->save();
                $ids[] = $quest->id;
            }

            $generation->forceFill([
                'status' => LlmGeneration::STATUS_SUCCEEDED,
                'output' => ['quests' => $parsed['quests'], 'quest_ids' => $ids],
                'moderation_result' => $moderation,
                'error' => null,
            ])->save();
        });
    }

    private function fail(LlmGeneration $generation, string $error): void
    {
        $generation->forceFill(['status' => LlmGeneration::STATUS_FAILED, 'error' => mb_substr($error, 0, 1000)])->save();
    }
}
