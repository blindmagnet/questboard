<?php

namespace App\Domain\AntiCheat\Rules;

use App\Domain\AntiCheat\CompletionContext;
use App\Domain\AntiCheat\RiskRule;
use App\Domain\AntiCheat\RiskSignal;

/** Completed faster than the quest's minimum possible duration. */
class TooFastRule implements RiskRule
{
    public function evaluate(CompletionContext $context): ?RiskSignal
    {
        $min = $context->quest->min_duration_ms;
        if ($min <= 0) {
            return null;
        }

        if ($context->durationMs === null) {
            return new RiskSignal('duration_missing', 20);
        }

        if ($context->durationMs < $min) {
            // Impossible speed (under half the minimum) is an immediate review.
            return $context->durationMs < intdiv($min, 2)
                ? new RiskSignal('duration_impossible', 70)
                : new RiskSignal('duration_too_fast', 40);
        }

        return null;
    }
}
