<?php

namespace App\Domain\Progression;

use InvalidArgumentException;

class XpCalculator
{
    /** @param array<string, float> $difficultyMultipliers */
    public function __construct(private readonly array $difficultyMultipliers) {}

    /**
     * xp = round(xp_reward × clamp(score / max_score, 0, 1) × difficulty_multiplier)
     */
    public function calculate(int $xpReward, int $score, int $maxScore, string $difficulty): int
    {
        if (! array_key_exists($difficulty, $this->difficultyMultipliers)) {
            throw new InvalidArgumentException("Unknown difficulty [{$difficulty}].");
        }

        if ($maxScore <= 0 || $xpReward <= 0) {
            return 0;
        }

        $factor = min(1.0, max(0.0, $score / $maxScore));

        return max(0, (int) round($xpReward * $factor * $this->difficultyMultipliers[$difficulty]));
    }

    /** Applies the remaining daily allowance to a calculated amount. */
    public function applyDailyCap(int $xp, int $alreadyEarnedToday, int $dailyCap): int
    {
        if ($dailyCap <= 0) {
            return $xp;
        }

        return max(0, min($xp, $dailyCap - $alreadyEarnedToday));
    }
}
