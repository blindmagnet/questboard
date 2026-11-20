<?php

use App\Domain\Progression\LevelCurve;

it('computes level at the boundaries', function (int $xp, int $level) {
    expect((new LevelCurve)->levelFor($xp))->toBe($level);
})->with([
    [0, 1], [99, 1], [100, 2], [399, 2], [400, 3], [899, 3], [900, 4], [1600, 5], [1599, 4], [-50, 1],
]);

it('is consistent with xpForLevel', function () {
    $curve = new LevelCurve;
    foreach (range(1, 200) as $level) {
        $xp = $curve->xpForLevel($level);
        expect($curve->levelFor($xp))->toBe($level)
            ->and($curve->levelFor(max(0, $xp - 1)))->toBe(max(1, $level - 1));
    }
});
