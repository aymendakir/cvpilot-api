<?php

namespace App\Support;

/**
 * Production must send session cookies over HTTPS only and with a known SameSite value.
 * `none` is allowed only when set explicitly (it needs the secure flag), never by default.
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
}
