<?php

namespace App\Domain\Llm;

class Moderation
{
    /** @param list<string> $blockedTerms */
    public function __construct(
        private readonly array $blockedTerms,
        private readonly int $maxTitle,
        private readonly int $maxDescription,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $quests
     * @return array{passed: bool, violations: list<array{index: int, reason: string}>}
     */
    public function check(array $quests): array
    {
        $violations = [];
        foreach ($quests as $i => $quest) {
            $title = (string) ($quest['title'] ?? '');
            $description = (string) ($quest['description'] ?? '');

            if (mb_strlen($title) > $this->maxTitle || mb_strlen($description) > $this->maxDescription) {
                $violations[] = ['index' => $i, 'reason' => 'too_long'];
            }

            $text = mb_strtolower($title.' '.$description);
            foreach ($this->blockedTerms as $term) {
                if ($term !== '' && str_contains($text, mb_strtolower($term))) {
                    $violations[] = ['index' => $i, 'reason' => 'blocked_term'];
                    break;
                }
            }

            if (preg_match('/<\s*script|javascript:|https?:\/\//i', $title.$description)) {
                $violations[] = ['index' => $i, 'reason' => 'unsafe_markup_or_link'];
            }
        }

        return ['passed' => $violations === [], 'violations' => $violations];
    }
}
