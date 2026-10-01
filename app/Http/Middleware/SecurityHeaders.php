<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /** Public, cookie-free routes that may be cached for a minute. */
    private const CACHEABLE_ROUTES = ['v1.blog.index', 'v1.blog.show'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        if (! $response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if ($request->is('api/*')) {
            // The public blog is the one cacheable API surface (SPEC §8.1); errors stay no-store.
            $cacheable = $response->getStatusCode() === 200 && in_array($request->route()?->getName(), self::CACHEABLE_ROUTES, true);
            $response->headers->set('Cache-Control', $cacheable ? 'public, max-age=60' : 'no-store, private');
            // JSON only: the Microsoft OAuth callback is an HTML page with inline styles.
            if ($request->route()?->getName() !== 'smtp.microsoft.callback') {
                $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
                $response->headers->set('Cross-Origin-Resource-Policy', 'same-site');
            }
        }
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
