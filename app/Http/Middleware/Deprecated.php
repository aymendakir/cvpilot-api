<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks legacy (pre-/api/v1) routes as deprecated: Deprecation: true, an
 * optional Sunset date (config api.legacy_sunset) and a Link header to the
 * successor route when one is declared, e.g. `deprecated:/api/v1/auth/login`.
 * Successor paths may contain {parameter} placeholders filled from the route.
 */
class Deprecated
{
    public function handle(Request $request, Closure $next, ?string $successor = null): Response
    {
        $response = $next($request);

        $response->headers->set('Deprecation', 'true');

        $sunset = config('api.legacy_sunset');
        if (is_string($sunset) && $sunset !== '') {
            $response->headers->set('Sunset', gmdate('D, d M Y H:i:s', strtotime($sunset)).' GMT');
        }

        if ($successor !== null && $successor !== '') {
            $response->headers->set('Link', '<'.$this->resolve($request, $successor).'>; rel="successor-version"');
        }

        return $response;
    }

    private function resolve(Request $request, string $successor): string
    {
        $parameters = $request->route()?->parameters() ?? [];

        return preg_replace_callback('/\{(\w+)\}/', function (array $m) use ($parameters) {
            $value = $parameters[$m[1]] ?? null;

            return is_object($value) && method_exists($value, 'getRouteKey') ? (string) $value->getRouteKey() : (string) ($value ?? $m[0]);
        }, $successor);
    }
}
