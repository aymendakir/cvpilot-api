<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
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
            $response->headers->set('Cache-Control', 'no-store, private');
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
