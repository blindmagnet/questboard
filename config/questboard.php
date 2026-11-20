<?php

return [

    'progression' => [
        'difficulty_multipliers' => [
            'easy' => 1.0,
            'medium' => 1.25,
            'hard' => 1.5,
        ],
        // Fallback daily XP cap when a game does not define one.
        'default_daily_xp_cap' => (int) env('QB_DEFAULT_DAILY_XP_CAP', 5000),
    ],

    'anti_cheat' => [
        // Completions with a risk score >= threshold are held for review (no XP applied).
        'review_threshold' => (int) env('QB_RISK_THRESHOLD', 60),
        'burst_window_seconds' => 60,
        'burst_max_completions' => 10,
        // Accepted clock skew / replay window for signed game-server requests.
        'signature_tolerance_seconds' => (int) env('QB_SIGNATURE_TOLERANCE', 300),
    ],

    'rate_limits' => [
        'api_per_minute' => (int) env('QB_RATE_LIMIT_API', 120),
        'server_per_minute' => (int) env('QB_RATE_LIMIT_SERVER', 1200),
        'generate_per_minute' => (int) env('QB_RATE_LIMIT_GENERATE', 5),
    ],

    'leaderboard' => [
        'connection' => env('QB_LEADERBOARD_REDIS', 'default'),
        'weekly_ttl_days' => 21,
        'max_page_size' => 100,
        'neighbours' => 2,
    ],

    'idempotency' => [
        'ttl_hours' => 24,
        'lock_seconds' => 10,
    ],

    'outbox' => [
        'batch_size' => 100,
        'max_attempts' => 10,
    ],

    'webhooks' => [
        'max_attempts' => 6,
        'backoff_seconds' => [10, 30, 90, 270, 810],
        'disable_after_consecutive_failures' => 5,
        'timeout_seconds' => 5,
        // Allow plain http and private hosts (local development only).
        'allow_insecure_urls' => (bool) env('QB_WEBHOOKS_ALLOW_INSECURE', false),
    ],

    'llm' => [
        // "fake" (default, no network) or "http" (OpenAI-compatible chat completions API).
        'driver' => env('LLM_DRIVER', 'fake'),
        'base_url' => env('LLM_BASE_URL', 'https://api.openai.com/v1'),
        'api_key' => env('LLM_API_KEY'),
        'model' => env('LLM_MODEL', 'gpt-4o-mini'),
        'timeout_seconds' => (int) env('LLM_TIMEOUT', 30),
        'retries' => 2,
        'circuit_breaker' => [
            'failure_threshold' => 5,
            'cooldown_seconds' => 120,
        ],
        // USD per 1K tokens, used only for cost estimates.
        'price_per_1k_input' => (float) env('LLM_PRICE_IN', 0.00015),
        'price_per_1k_output' => (float) env('LLM_PRICE_OUT', 0.0006),
        'max_count' => 10,
        'moderation' => [
            'blocked_terms' => ['kill yourself', 'suicide', 'nazi', 'porn', 'rape'],
            'max_title_length' => 120,
            'max_description_length' => 1000,
        ],
    ],

    'metrics' => [
        // Bearer token required for /metrics. Requests from private networks are allowed without it.
        'token' => env('METRICS_TOKEN'),
    ],
];
