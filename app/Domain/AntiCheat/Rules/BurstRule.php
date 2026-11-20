<?php

namespace App\Domain\AntiCheat\Rules;

use App\Domain\AntiCheat\CompletionContext;
use App\Domain\AntiCheat\RiskRule;
use App\Domain\AntiCheat\RiskSignal;

/** Too many completions in a short window. */
class BurstRule implements RiskRule
{
    public function __construct(private readonly int $maxCompletions) {}

    public function evaluate(CompletionContext $context): ?RiskSignal
    {
        return $context->recentCompletions >= $this->maxCompletions
            ? new RiskSignal('completion_burst', 60)
            : null;
    }
}
