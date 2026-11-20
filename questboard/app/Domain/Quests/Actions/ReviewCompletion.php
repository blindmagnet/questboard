<?php

namespace App\Domain\Quests\Actions;

use App\Domain\Progression\XpCalculator;
use App\Jobs\PublishOutboxJob;
use App\Models\GamePlayer;
use App\Models\QuestCompletion;
use App\Support\AuditLogger;
use App\Support\ProblemException;
use Illuminate\Support\Facades\DB;

/** Game-admin decision on a completion that the anti-cheat layer held for review. */
class ReviewCompletion
{
    public function __construct(
        private readonly CompleteQuest $complete,
        private readonly XpCalculator $xp,
        private readonly AuditLogger $audit,
    ) {}

    public function approve(QuestCompletion $completion): QuestCompletion
    {
        $completion = DB::transaction(function () use ($completion) {
            $completion = $this->lockFlagged($completion);
            $quest = $completion->quest;

            /** @var GamePlayer $player */
            $player = GamePlayer::withoutGlobalScopes()->whereKey($completion->player_id)->lockForUpdate()->firstOrFail();

            $xp = $this->xp->calculate($quest->xp_reward, $completion->score, $quest->max_score, $quest->difficulty);
            $completion->forceFill(['status' => QuestCompletion::STATUS_ACCEPTED, 'xp_awarded' => $xp, 'reviewed_at' => now()])->save();
            $this->complete->applyXp($player, $xp, $completion, $quest);

            return $completion;
        });

        $this->audit->log('completion.approved', $completion, ['xp_awarded' => $completion->xp_awarded]);
        PublishOutboxJob::trigger();

        return $completion;
    }

    public function reject(QuestCompletion $completion): QuestCompletion
    {
        $completion = DB::transaction(function () use ($completion) {
            $completion = $this->lockFlagged($completion);
            // Freeing the once-guard lets the player legitimately retry a non-repeatable quest.
            $completion->forceFill(['status' => QuestCompletion::STATUS_REJECTED, 'once_guard' => null, 'reviewed_at' => now()])->save();

            return $completion;
        });

        $this->audit->log('completion.rejected', $completion);

        return $completion;
    }

    private function lockFlagged(QuestCompletion $completion): QuestCompletion
    {
        /** @var QuestCompletion $locked */
        $locked = QuestCompletion::withoutGlobalScopes()->whereKey($completion->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== QuestCompletion::STATUS_FLAGGED) {
            throw new ProblemException(409, 'not-flagged', 'Only flagged completions can be reviewed.');
        }

        return $locked;
    }
}
