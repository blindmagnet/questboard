<?php

namespace App\Domain\Progression;

/**
 * level = floor(sqrt(xp / 100)) + 1
 *
 * Bound in the container so a game-specific curve can replace it.
 */
class LevelCurve
{
    public function levelFor(int $xp): int
    {
        $xp = max(0, $xp);

        $level = (int) floor(sqrt($xp / 100)) + 1;

        // Guard against floating point error right at the boundaries.
        while ($this->xpForLevel($level + 1) <= $xp) {
            $level++;
        }
        while ($level > 1 && $this->xpForLevel($level) > $xp) {
            $level--;
        }

        return $level;
    }

    /** Minimum XP needed to reach the given level. */
    public function xpForLevel(int $level): int
    {
        $level = max(1, $level);

        return 100 * ($level - 1) ** 2;
    }
}
