<?php

namespace App\Domain\AntiCheat;

class RiskScorer
{
    /** @param iterable<RiskRule> $rules */
    public function __construct(private readonly iterable $rules, private readonly int $threshold) {}

    public function assess(CompletionContext $context): RiskAssessment
    {
        $score = 0;
        $reasons = [];

        foreach ($this->rules as $rule) {
            $signal = $rule->evaluate($context);
            if ($signal !== null) {
                $score += $signal->points;
                $reasons[] = $signal->reason;
            }
        }

        // Results reported by an authenticated, signed game server are trusted more.
        if ($context->fromTrustedServer) {
            $score = intdiv($score, 2);
        }

        return new RiskAssessment(min($score, 100), $reasons, $score >= $this->threshold);
    }
}
