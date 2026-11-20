<?php

namespace App\Domain\Quests\Actions;

use App\Domain\AntiCheat\CompletionContext;
use App\Domain\AntiCheat\RiskScorer;
use App\Domain\Outbox\Outbox;
use App\Domain\Progression\LevelCurve;
use App\Domain\Progression\XpCalculator;
use App\Domain\Quests\CompletionInput;
use App\Domain\Quests\CompletionResult;
use App\Jobs\PublishOutboxJob;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Quest;
use App\Models\QuestCompletion;
use App\Models\SeasonScore;
use App\Support\Metrics;
use App\Support\ProblemException;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Records a quest completion. The whole write path (completion row, XP, level,
 * season points, outbox events) is a single short transaction; leaderboard,
 * achievements and webhooks are projected asynchronously from the outbox.
 */
class CompleteQuest
{
    public function __construct(
        private readonly XpCalculator $xp,
        private readonly LevelCurve $levels,
        private readonly RiskScorer $risk,
        private readonly Outbox $outbox,
        private readonly Metrics $metrics,
    ) {}

    public function handle(GamePlayer $player, Quest $quest, CompletionInput $input): CompletionResult
    {
        if ($quest->game_id !== $player->game_id) {
            throw new ProblemException(404, 'not-found', 'Quest not found.');
        }

        if (! $quest->isPlayable()) {
            throw new ProblemException(422, 'quest-not-available', 'This quest is not published.');
        }

        $completedAt = $input->completedAt ?? now();
        if ($completedAt->isAfter(now()->addSeconds(5))) {
            $this->reject($player, 'completed_at_in_future');
            throw new ProblemException(422, 'invalid-completion', 'completed_at cannot be in the future.');
        }
        if ($completedAt->isBefore(now()->subDay())) {
            throw new ProblemException(422, 'invalid-completion', 'completed_at is too old.');
        }
        if ($input->score > $quest->max_score) {
            $this->reject($player, 'score_above_max');
            throw new ProblemException(422, 'invalid-completion', 'score exceeds the quest max_score.', ['max_score' => $quest->max_score]);
        }

        try {
            $result = DB::transaction(fn () => $this->record($player, $quest, $input, $completedAt));
        } catch (UniqueConstraintViolationException) {
            // Lost a race against a concurrent request (same key or same non-repeatable quest).
            throw new ProblemException(409, 'conflict', 'A concurrent completion for this quest was already recorded.');
        }

        if (! $result->replayed) {
            $this->metrics->increment('quest_completions_total', ['status' => $result->completion->status]);
            PublishOutboxJob::trigger(afterCommit: true);
        }

        return $result;
    }

    private function record(GamePlayer $player, Quest $quest, CompletionInput $input, CarbonInterface $completedAt): CompletionResult
    {
        /** @var GamePlayer $player */
        $player = GamePlayer::withoutGlobalScopes()->whereKey($player->id)->lockForUpdate()->firstOrFail();

        if ($player->isBanned()) {
            throw new ProblemException(403, 'player-banned', 'This player is banned from the game.');
        }

        // Database-level idempotency (covers callers that bypass the HTTP middleware).
        $existing = QuestCompletion::withoutGlobalScopes()
            ->where('game_id', $player->game_id)
            ->where('idempotency_key', $input->idempotencyKey)
            ->first();
        if ($existing) {
            if ($existing->player_id !== $player->id || $existing->quest_id !== $quest->id) {
                throw new ProblemException(409, 'idempotency-key-reused', 'This Idempotency-Key was already used for a different request.');
            }

            return new CompletionResult($existing, $player, false, replayed: true);
        }

        $previous = QuestCompletion::withoutGlobalScopes()
            ->where('player_id', $player->id)
            ->where('quest_id', $quest->id)
            ->whereIn('status', [QuestCompletion::STATUS_ACCEPTED, QuestCompletion::STATUS_FLAGGED])
            ->latest('completed_at')
            ->first();

        if ($previous && ! $quest->is_repeatable) {
            throw new ProblemException(409, 'quest-already-completed', 'This quest can only be completed once.');
        }

        if ($previous && $quest->cooldown_seconds > 0) {
            $readyAt = $previous->completed_at->copy()->addSeconds($quest->cooldown_seconds);
            if ($readyAt->isFuture()) {
                throw new ProblemException(429, 'cooldown-active', 'Quest is on cooldown.', [
                    'retry_after' => (int) ceil(now()->diffInSeconds($readyAt)),
                ]);
            }
        }

        $recent = QuestCompletion::withoutGlobalScopes()
            ->where('player_id', $player->id)
            ->where('created_at', '>=', now()->subSeconds((int) config('questboard.anti_cheat.burst_window_seconds')))
            ->count();

        $assessment = $this->risk->assess(new CompletionContext(
            $player, $quest, $input->score, $input->durationMs, $completedAt, $recent, $input->reportedBy === 'server',
        ));

        $status = $assessment->requiresReview ? QuestCompletion::STATUS_FLAGGED : QuestCompletion::STATUS_ACCEPTED;
        $xp = 0;
        if ($status === QuestCompletion::STATUS_ACCEPTED) {
            $xp = $this->xp->applyDailyCap(
                $this->xp->calculate($quest->xp_reward, $input->score, $quest->max_score, $quest->difficulty),
                $this->earnedToday($player),
                $this->dailyCap($player),
            );
        }

        $completion = QuestCompletion::query()->create([
            'game_id' => $player->game_id,
            'player_id' => $player->id,
            'quest_id' => $quest->id,
            'once_guard' => $quest->is_repeatable ? null : $quest->id,
            'score' => $input->score,
            'duration_ms' => $input->durationMs,
            'xp_awarded' => $xp,
            'status' => $status,
            'risk_score' => $assessment->score,
            'risk_reasons' => $assessment->reasons ?: null,
            'reported_by' => $input->reportedBy,
            'idempotency_key' => $input->idempotencyKey,
            'client_meta' => $input->clientMeta,
            'completed_at' => $completedAt,
        ]);

        if ($status === QuestCompletion::STATUS_FLAGGED) {
            $player->risk_score = min(1000, $player->risk_score + $assessment->score);
            $player->save();

            $this->outbox->record('player.flagged', $player->game_id, [
                'player_id' => $player->id,
                'user_id' => $player->user_id,
                'completion_id' => $completion->id,
                'quest_id' => $quest->id,
                'risk_score' => $assessment->score,
                'reasons' => $assessment->reasons,
            ]);
            $this->metrics->increment('anticheat_flags_total', ['reason' => $assessment->reasons[0] ?? 'unknown']);

            return new CompletionResult($completion, $player, false);
        }

        $levelUp = $this->applyXp($player, $xp, $completion, $quest);

        return new CompletionResult($completion, $player, $levelUp);
    }

    /**
     * Applies XP to the (locked) player, credits the active season and records
     * the quest.completed outbox event. Shared with the review flow.
     */
    public function applyXp(GamePlayer $player, int $xp, QuestCompletion $completion, Quest $quest): bool
    {
        $oldLevel = $player->level;
        $player->xp += $xp;
        $player->level = $this->levels->levelFor($player->xp);
        $player->save();

        $season = Game::query()->find($player->game_id)?->activeSeason();
        if ($season && $xp > 0) {
            $updated = SeasonScore::query()
                ->where('season_id', $season->id)->where('player_id', $player->id)
                ->increment('points', $xp);
            if ($updated === 0) {
                SeasonScore::query()->create(['season_id' => $season->id, 'player_id' => $player->id, 'points' => $xp]);
            }
        }

        $this->outbox->record('quest.completed', $player->game_id, [
            'completion_id' => $completion->id,
            'player_id' => $player->id,
            'user_id' => $player->user_id,
            'quest_id' => $quest->id,
            'difficulty' => $quest->difficulty,
            'score' => $completion->score,
            'xp_awarded' => $xp,
            'player_xp' => $player->xp,
            'level' => $player->level,
            'level_up' => $player->level > $oldLevel,
            'season_id' => $season?->id,
            'shadow_banned' => $player->isShadowBanned(),
            'completed_at' => $completion->completed_at->toIso8601String(),
        ]);

        return $player->level > $oldLevel;
    }

    private function earnedToday(GamePlayer $player): int
    {
        return (int) QuestCompletion::withoutGlobalScopes()
            ->where('player_id', $player->id)
            ->where('status', QuestCompletion::STATUS_ACCEPTED)
            ->where('completed_at', '>=', now()->startOfDay())
            ->sum('xp_awarded');
    }

    private function dailyCap(GamePlayer $player): int
    {
        $game = Game::query()->find($player->game_id);

        return (int) ($game->quota_daily_xp_per_player ?? config('questboard.progression.default_daily_xp_cap'));
    }

    private function reject(GamePlayer $player, string $reason): void
    {
        GamePlayer::withoutGlobalScopes()->whereKey($player->id)->increment('risk_score', 10);
        $this->metrics->increment('anticheat_rejections_total', ['reason' => $reason]);
    }
}
