<?php

namespace App\Domain\Webhooks;

/**
 * SSRF protection for webhook targets: HTTPS only and no private, loopback,
 * link-local or reserved addresses (checked at registration and before every send).
 */
class UrlGuard
{
    public function __construct(private readonly bool $allowInsecure) {}

    public function isAllowed(string $url): bool
    {
        if ($this->allowInsecure) {
            return filter_var($url, FILTER_VALIDATE_URL) !== false;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user'])) {
            return false;
        }

        $host = trim($parts['host'], '[]');
        if (in_array(strtolower($host), ['localhost', 'metadata.google.internal'], true) || str_ends_with(strtolower($host), '.local')) {
            return false;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }
}
