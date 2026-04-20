<?php

namespace App\Ai\Tools\Guards;

/**
 * Host allow/deny check for outbound HTTP requests. Supports exact-host
 * matching, a leading wildcard for subdomain trees (e.g. `*.example.com`),
 * and IPv4 CIDR blocks (e.g. `10.0.0.0/8`) — intentionally narrow so
 * operators can inspect the full list in config/gail.php.
 */
final class HostGuard
{
    /**
     * @param  list<string>  $deniedHosts
     */
    public function __construct(
        private readonly array $deniedHosts = [],
    ) {}

    /**
     * Return the blocked pattern that matched, or null if the URL is allowed.
     */
    public function deniedHostFor(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        foreach ($this->deniedHosts as $pattern) {
            if ($this->matches($host, $pattern)) {
                return $pattern;
            }
        }

        return null;
    }

    private function matches(string $host, string $pattern): bool
    {
        if (str_starts_with($pattern, '*.')) {
            $suffix = substr($pattern, 1); // leaves ".example.com"

            return str_ends_with(strtolower($host), strtolower($suffix));
        }

        if (str_contains($pattern, '/') && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->matchesCidr($host, $pattern);
        }

        return strtolower($host) === strtolower($pattern);
    }

    private function matchesCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);

        if (! filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $bits = (int) $bits;

        if ($bits < 0 || $bits > 32) {
            return false;
        }

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    public function allows(string $url): bool
    {
        return $this->deniedHostFor($url) === null;
    }

    /**
     * Build a guard for a named tool. Merges the shared baseline at
     * `gail.tools.denied_hosts` with any tool-specific
     * `gail.tools.<tool>.extra_denied_hosts` additions, so operators edit
     * the baseline in one place while individual tools can still widen it.
     */
    public static function forTool(string $tool): self
    {
        $shared = (array) config('gail.tools.denied_hosts', []);
        $extra = (array) config("gail.tools.{$tool}.extra_denied_hosts", []);

        return new self(
            deniedHosts: array_values(array_unique([...$shared, ...$extra])),
        );
    }
}
