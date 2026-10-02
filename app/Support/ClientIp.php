<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The visitor's address for the anonymous limits. Behind Cloudflare, $request->ip() is a Cloudflare edge,
 * so CF-Connecting-IP is used, but only when TRUST_CF_CONNECTING_IP says the origin is reachable through
 * Cloudflare alone; otherwise the header is ignored, because anyone could set it.
 */
final class ClientIp
{
    public static function of(Request $request): string
    {
        if (config('anonymous.trust_cf_connecting_ip')) {
            $header = trim((string) $request->header('CF-Connecting-IP'));
            if (filter_var($header, FILTER_VALIDATE_IP) !== false) {
                return $header;
            }
        }

        return (string) $request->ip();
    }

    /** A keyed hash, so rate-limit keys in the cache never hold the address itself. */
    public static function hashed(Request $request): string
    {
        return hash_hmac('sha256', self::of($request), (string) config('app.key'));
    }
}
