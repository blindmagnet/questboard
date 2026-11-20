<?php

use App\Domain\Llm\Moderation;
use App\Domain\Llm\QuestSchema;

function llmQuests(int $n, array $override = []): string
{
    return json_encode(['quests' => array_fill(0, $n, $override + [
        'title' => 'Find the key', 'description' => 'Search the cave.', 'difficulty' => 'easy',
        'xp_reward' => 100, 'max_score' => 1000, 'min_duration_ms' => 5000,
    ])]);
}

it('accepts schema-valid output, including a fenced json block', function () {
    $schema = new QuestSchema;
    expect($schema->parse(llmQuests(2), 2)['valid'])->toBeTrue()
        ->and($schema->parse("```json\n".llmQuests(1)."\n```", 1)['valid'])->toBeTrue();
});

it('rejects invalid output', function (string $content, int $count) {
    expect((new QuestSchema)->parse($content, $count)['valid'])->toBeFalse();
})->with([
    'not json' => ['Sure! Here are your quests', 1],
    'wrong count' => [llmQuests(2), 3],
    'bad difficulty' => [llmQuests(1, ['difficulty' => 'insane']), 1],
    'xp too high' => [llmQuests(1, ['xp_reward' => 999999]), 1],
    'extra keys' => [llmQuests(1, ['run' => 'rm -rf /']), 1],
]);

it('moderates blocked terms, links and length', function () {
    $mod = new Moderation(['nazi'], 120, 1000);

    expect($mod->check([['title' => 'Nice quest', 'description' => 'ok']])['passed'])->toBeTrue()
        ->and($mod->check([['title' => 'NAZI gold', 'description' => '']])['violations'][0]['reason'])->toBe('blocked_term')
        ->and($mod->check([['title' => 'x', 'description' => 'visit https://evil.test']])['passed'])->toBeFalse()
        ->and($mod->check([['title' => str_repeat('a', 121), 'description' => '']])['passed'])->toBeFalse();
});
