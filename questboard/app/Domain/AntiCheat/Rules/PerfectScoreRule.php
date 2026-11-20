<?php

namespace App\Domain\AntiCheat\Rules;

use App\Domain\AntiCheat\CompletionContext;
use App\Domain\AntiCheat\RiskRule;
use App\Domain\AntiCheat\RiskSignal;

/** A perfect score on a hard quest is suspicious when combined with other signals. */
class PerfectScoreRule implements RiskRule
{
    public function evaluate(CompletionContext $context): ?RiskSignal
    {
        return $context->quest->difficulty === 'hard' && $context->score >= $context->quest->max_score
            ? new RiskSignal('perfect_hard_score', 15)
            : null;
    }
}
