<?php

namespace App\Support;

use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Minimal Prometheus-compatible metrics stored in Redis so every PHP-FPM worker
 * and queue worker contributes to the same series. Failures never break requests.
 */
class Metrics
{
    private const COUNTERS = 'metrics:counters';

    private const HISTOGRAMS = 'metrics:histograms';

    public const BUCKETS = [0.01, 0.025, 0.05, 0.08, 0.1, 0.25, 0.3, 0.5, 1, 2.5, 5];

    public function __construct(private readonly RedisManager $redis) {}

    /** @param array<string, string|int> $labels */
    public function increment(string $name, array $labels = [], float $by = 1): void
    {
        $this->safely(fn () => $this->redis->connection()->hincrbyfloat(self::COUNTERS, $this->series($name, $labels), $by));
    }

    /** @param array<string, string|int> $labels */
    public function observe(string $name, float $seconds, array $labels = []): void
    {
        $fields = [];
        foreach (self::BUCKETS as $le) {
            if ($seconds <= $le) {
                $fields[] = $this->series($name.'_bucket', $labels + ['le' => (string) $le]);
            }
        }
        $fields[] = $this->series($name.'_bucket', $labels + ['le' => '+Inf']);
        $fields[] = $this->series($name.'_count', $labels);
        $sum = $this->series($name.'_sum', $labels);

        // One round-trip for the whole histogram update.
        $this->safely(fn () => $this->redis->connection()->pipeline(function ($pipe) use ($fields, $sum, $seconds) {
            foreach ($fields as $field) {
                $pipe->hincrby(self::HISTOGRAMS, $field, 1);
            }
            $pipe->hincrbyfloat(self::HISTOGRAMS, $sum, $seconds);
        }));
    }

    /**
     * Renders the Prometheus text exposition format.
     *
     * @param  array<string, float|int>  $gauges
     */
    public function render(array $gauges = []): string
    {
        $lines = [];
        $all = $this->safely(fn () => array_merge(
            $this->redis->connection()->hgetall(self::COUNTERS) ?: [],
            $this->redis->connection()->hgetall(self::HISTOGRAMS) ?: [],
        )) ?? [];

        ksort($all);
        foreach ($all as $series => $value) {
            $lines[] = 'questboard_'.$series.' '.$value;
        }
        foreach ($gauges as $series => $value) {
            $lines[] = 'questboard_'.$series.' '.$value;
        }

        return implode("\n", $lines)."\n";
    }

    /** @param array<string, string|int> $labels */
    private function series(string $name, array $labels): string
    {
        if ($labels === []) {
            return $name;
        }
        ksort($labels);
        $parts = [];
        foreach ($labels as $k => $v) {
            $parts[] = $k.'="'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', ''], (string) $v).'"';
        }

        return $name.'{'.implode(',', $parts).'}';
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T|null
     */
    private function safely(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            Log::debug('metrics.unavailable', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
