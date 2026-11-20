<?php

namespace App\Domain\AntiCheat;

/**
 * HMAC-SHA256 signatures for game-server requests and outgoing webhooks.
 *
 * Canonical string: "{timestamp}\n{METHOD}\n{path}\n{raw body}"
 */
class RequestSigner
{
    public static function canonical(string $timestamp, string $method, string $path, string $body): string
    {
        return $timestamp."\n".strtoupper($method)."\n".'/'.ltrim($path, '/')."\n".$body;
    }

    public static function sign(string $secret, string $payload): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    public static function verify(string $secret, string $payload, string $signature): bool
    {
        return hash_equals(self::sign($secret, $payload), strtolower($signature));
    }

    public static function isFresh(int $timestamp, int $toleranceSeconds, ?int $now = null): bool
    {
        return abs(($now ?? time()) - $timestamp) <= $toleranceSeconds;
    }
}
