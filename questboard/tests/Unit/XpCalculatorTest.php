<?php

use App\Domain\Progression\XpCalculator;

beforeEach(fn () => $this->calc = new XpCalculator(['easy' => 1.0, 'medium' => 1.25, 'hard' => 1.5]));

it('scales xp by score ratio and difficulty', function (int $reward, int $score, int $max, string $difficulty, int $expected) {
    expect($this->calc->calculate($reward, $score, $max, $difficulty))->toBe($expected);
})->with([
    'full score easy' => [100, 1000, 1000, 'easy', 100],
    'half score medium' => [100, 500, 1000, 'medium', 63],   // 62.5 rounds half up
    'example from spec' => [100, 870, 1000, 'hard', 131],    // 100 * 0.87 * 1.5 = 130.5
    'zero score' => [100, 0, 1000, 'hard', 0],
    'clamped above max' => [100, 5000, 1000, 'easy', 100],
    'zero max score' => [100, 10, 0, 'easy', 0],
]);

it('rejects unknown difficulty', function () {
    $this->calc->calculate(100, 1, 1, 'legendary');
})->throws(InvalidArgumentException::class);

it('applies the daily cap without going negative', function () {
    expect($this->calc->applyDailyCap(100, 0, 5000))->toBe(100)
        ->and($this->calc->applyDailyCap(100, 4950, 5000))->toBe(50)
        ->and($this->calc->applyDailyCap(100, 6000, 5000))->toBe(0)
        ->and($this->calc->applyDailyCap(100, 999999, 0))->toBe(100);
});
