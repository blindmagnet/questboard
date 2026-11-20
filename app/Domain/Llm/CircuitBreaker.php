<?php

namespace App\Domain\Llm;

use Illuminate\Contracts\Cache\Repository;

/**
 * Shared (cache-backed) circuit breaker: after N consecutive failures the
 * provider is not called for a cooldown period, so an outage cannot pile up
 * slow jobs. Core APIs never depend on it.
 */
class CircuitBreaker
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $name,
        private readonly int $threshold,
        private readonly int $cooldownSeconds,
    ) {}

    public function isOpen(): bool
    {
        return (bool) $this->cache->get("cb:{$this->name}:open", false);
    }

    public function recordSuccess(): void
    {
        $this->cache->forget("cb:{$this->name}:failures");
    }

    public function recordFailure(): void
    {
        $failures = (int) $this->cache->increment("cb:{$this->name}:failures");
        if ($failures >= $this->threshold) {
            $this->cache->put("cb:{$this->name}:open", true, $this->cooldownSeconds);
            $this->cache->forget("cb:{$this->name}:failures");
        }
    }
}
