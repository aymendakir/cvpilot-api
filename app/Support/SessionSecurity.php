<?php

namespace App\Support;

/**
 * Production must send session cookies over HTTPS only and with a known SameSite value.
 * `none` is allowed only when set explicitly (it needs the secure flag), never by default.
 * With `lax` or `strict` the app, the marketing site and the API must sit on one domain, or nobody can
 * sign in; production refuses to boot instead of failing silently (API-B decision D2).
 */
final class SessionSecurity
{
    private const SAME_SITE = ['lax', 'strict', 'none'];

    public static function assertSafe(string $environment, mixed $secure, mixed $sameSite): void
    {
        if ($environment !== 'production') {
            return;
        }

        if ($secure !== true) {
            throw new \RuntimeException('Refusing to boot: SESSION_SECURE_COOKIE must be true in production.');
        }

        if (! is_string($sameSite) || ! in_array(strtolower($sameSite), self::SAME_SITE, true)) {
            throw new \RuntimeException('Refusing to boot: SESSION_SAME_SITE must be one of lax, strict or none in production.');
        }
    }

    /**
     * With SameSite lax/strict, every site that signs in through the API must be on the same domain as
     * the API: under SESSION_DOMAIN when it is set, else under the parent of the API host
     * (api.example.com → example.com).
     *
     * @param  array<string, string|null>  $urls  setting name → URL (APP_URL, FRONTEND_URL, SITE_URL); empty ones are skipped
     */
    public static function assertSameSiteHosts(string $environment, mixed $sameSite, ?string $domain, array $urls): void
    {
        if ($environment !== 'production' || ! is_string($sameSite) || strtolower($sameSite) === 'none') {
            return;
        }
        $hosts = [];
        foreach ($urls as $name => $url) {
            $host = is_string($url) && $url !== '' ? parse_url($url, PHP_URL_HOST) : null;
            if (is_string($host) && $host !== '') {
                $hosts[$name] = strtolower($host);
            }
        }
        $base = ltrim(strtolower(trim((string) $domain)), '.');
        if ($base === '' && isset($hosts['APP_URL'])) {
            $dot = strpos($hosts['APP_URL'], '.');
            $base = $dot === false ? $hosts['APP_URL'] : substr($hosts['APP_URL'], $dot + 1);
        }
        if ($base === '') {
            return;
        }
        foreach ($hosts as $name => $host) {
            if ($host !== $base && ! str_ends_with($host, '.'.$base)) {
                throw new \RuntimeException("Refusing to boot: with SESSION_SAME_SITE={$sameSite}, {$name} ({$host}) must be on {$base}. Fix {$name} or SESSION_DOMAIN, or keep SESSION_SAME_SITE=none until every host is on one domain (docs/DEPLOYMENT.md section 6).");
            }
        }
    }
}
