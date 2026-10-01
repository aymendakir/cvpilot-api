<?php

namespace App\Support;

/**
 * Parses the TRUSTED_PROXIES env value: unset or blank trusts no proxy (the safe default),
 * `*` trusts the platform proxy in front of the container, otherwise a comma separated list of addresses or CIDR ranges.
 */
final class TrustedProxies
{
    /** @return array<int, string>|string */
    public static function parse(?string $value): array|string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }
        if ($value === '*') {
            return '*';
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $item) => $item !== ''));
    }
}
