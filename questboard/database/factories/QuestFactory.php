<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\Quest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Quest> */
class QuestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'title' => ucfirst(fake()->words(3, true)),
            'description' => fake()->sentence(),
            'difficulty' => 'easy',
            'xp_reward' => 100,
            'max_score' => 1000,
            'min_duration_ms' => 0,
            'cooldown_seconds' => 0,
            'is_repeatable' => false,
            'is_active' => true,
            'published_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(['is_active' => false, 'published_at' => null]);
    }

    public function repeatable(int $cooldown = 0): static
    {
        return $this->state(['is_repeatable' => true, 'cooldown_seconds' => $cooldown]);
    }
}
