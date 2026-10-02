<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only the API's own host names are answered (API-B decision D1): TRUSTED_HOSTS, or the host of APP_URL
 * when it is empty. Laravel builds absolute URLs (the Microsoft sign-in redirect, APP_URL fallbacks)
 * from the Host header, so a forged one could poison links or a cache in front. A request for another
 * host is refused before any route runs. Not enforced in local and testing. `/up` stays open for the
 * host's own health check, which may use the container address.
 */
class TrustHosts
{
    public function handle(Request $request, Closure $next): Response
    {
        $enforce = ! app()->environment(['local', 'testing']) && ! $request->is('up');
        Request::setTrustedHosts($enforce ? self::patterns() : []);

        return $next($request);
    }

    /** @return list<string> host names, from TRUSTED_HOSTS or APP_URL */
    public static function hosts(): array
    {
        $configured = array_values(array_filter(array_map(
            fn (string $host) => strtolower(trim($host)),
            explode(',', (string) config('app.trusted_hosts')),
        )));
        if ($configured !== []) {
            return $configured;
        }
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($appHost) && $appHost !== '' ? [strtolower($appHost)] : [];
    }

    /** @return list<string> Symfony matches these as unanchored regexes, so each one is anchored and escaped. */
    private static function patterns(): array
    {
        return array_map(fn (string $host) => '^'.preg_quote($host).'$', self::hosts());
    }
}
