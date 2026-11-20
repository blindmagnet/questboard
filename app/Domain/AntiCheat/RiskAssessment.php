<?php

namespace App\Domain\AntiCheat;

final readonly class RiskAssessment
{
    /** @param list<string> $reasons */
    public function __construct(public int $score, public array $reasons, public bool $requiresReview) {}
}
