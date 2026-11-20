<?php

use App\Domain\AntiCheat\CompletionContext;
use App\Domain\AntiCheat\RiskScorer;
use App\Domain\AntiCheat\Rules\BurstRule;
use App\Domain\AntiCheat\Rules\PerfectScoreRule;
use App\Domain\AntiCheat\Rules\TooFastRule;
use App\Models\GamePlayer;
use App\Models\Quest;

function riskContext(array $quest = [], int $score = 500, ?int $duration = 30000, int $recent = 0, bool $server = false): CompletionContext
{
    $q = new Quest($quest + ['difficulty' => 'easy', 'max_score' => 1000, 'min_duration_ms' => 10000]);

    return new CompletionContext(new GamePlayer, $q, $score, $duration, now(), $recent, $server);
}

beforeEach(fn () => $this->scorer = new RiskScorer([new TooFastRule, new BurstRule(10), new PerfectScoreRule], 60));

it('accepts a normal completion', function () {
    $r = $this->scorer->assess(riskContext());
    expect($r->score)->toBe(0)->and($r->requiresReview)->toBeFalse();
});

it('flags impossible durations for review', function () {
    $r = $this->scorer->assess(riskContext(duration: 1000));
    expect($r->reasons)->toBe(['duration_impossible'])->and($r->requiresReview)->toBeTrue();
});

it('adds risk for slightly-too-fast completions without blocking', function () {
    $r = $this->scorer->assess(riskContext(duration: 8000));
    expect($r->score)->toBe(40)->and($r->requiresReview)->toBeFalse();
});

it('combines signals', function () {
    $r = $this->scorer->assess(riskContext(['difficulty' => 'hard'], score: 1000, duration: 8000));
    expect($r->reasons)->toBe(['duration_too_fast', 'perfect_hard_score'])->and($r->score)->toBe(55);
});

it('flags bursts', function () {
    expect($this->scorer->assess(riskContext(recent: 10))->requiresReview)->toBeTrue();
});

it('trusts signed game servers more', function () {
    expect($this->scorer->assess(riskContext(duration: 1000, server: true))->requiresReview)->toBeFalse();
});
