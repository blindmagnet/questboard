<?php

namespace App\Domain\AntiCheat;

final readonly class RiskSignal
{
    public function __construct(public string $reason, public int $points) {}
}
