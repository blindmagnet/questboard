<?php

namespace App\Support;

use RuntimeException;

/**
 * Domain error rendered as application/problem+json (RFC 7807).
 */
class ProblemException extends RuntimeException
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public readonly int $status,
        public readonly string $type,
        string $detail,
        public readonly array $extra = [],
    ) {
        parent::__construct($detail);
    }
}
