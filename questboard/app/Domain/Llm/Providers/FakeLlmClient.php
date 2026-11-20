<?php

namespace App\Domain\Llm\Providers;

use App\Domain\Llm\LlmClient;
use App\Domain\Llm\LlmException;
use App\Domain\Llm\LlmResponse;

/**
 * Deterministic offline client. Default in tests and whenever no API key is set.
 * Responses can be scripted with queue() to simulate invalid output or outages.
 */
class FakeLlmClient implements LlmClient
{
    /** @var list<string|LlmException> */
    private array $queue = [];

    /** @var list<list<array{role: string, content: string}>> */
    public array $calls = [];

    public function queue(string|LlmException ...$responses): self
    {
        array_push($this->queue, ...$responses);

        return $this;
    }

    public function complete(array $messages): LlmResponse
    {
        $this->calls[] = $messages;

        $next = array_shift($this->queue);
        if ($next instanceof LlmException) {
            throw $next;
        }

        $content = $next ?? $this->generate($messages);

        return new LlmResponse($content, (int) ceil(strlen(json_encode($messages) ?: '') / 4), (int) ceil(strlen($content) / 4));
    }

    /** @param list<array{role: string, content: string}> $messages */
    private function generate(array $messages): string
    {
        $data = [];
        $last = end($messages)['content'] ?? '';
        if (preg_match('/<input>(.*?)<\/input>/s', $last, $m)) {
            $data = json_decode($m[1], true) ?: [];
        }

        $theme = (string) ($data['theme'] ?? 'adventure');
        $difficulty = (string) ($data['difficulty'] ?? 'easy');
        $count = max(1, min(10, (int) ($data['count'] ?? 3)));

        $quests = [];
        for ($i = 1; $i <= $count; $i++) {
            $quests[] = [
                'title' => ucfirst($theme)." challenge #{$i}",
                'description' => "Complete the {$theme} trial number {$i}.",
                'difficulty' => $difficulty,
                'xp_reward' => 50 * $i,
                'max_score' => 1000,
                'min_duration_ms' => 10000,
            ];
        }

        return (string) json_encode(['quests' => $quests]);
    }
}
