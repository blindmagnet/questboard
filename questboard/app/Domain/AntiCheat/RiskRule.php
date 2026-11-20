<?php

namespace App\Domain\AntiCheat;

interface RiskRule
{
    /** Returns [points, reason] when the rule fires, or null. */
    public function evaluate(CompletionContext $context): ?RiskSignal;
}
