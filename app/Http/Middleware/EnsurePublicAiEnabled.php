<?php

namespace App\Http\Middleware;

use App\Exceptions\UpstreamUnavailableException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PUBLIC_AI_ENABLED=false switches the anonymous AI routes off (API-D): 503 before the limits count the
 * request or Turnstile is asked, so a switched-off route costs nothing.
 */
class EnsurePublicAiEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('anonymous.ai_enabled')) {
            throw new UpstreamUnavailableException('The anonymous AI tools are switched off.');
        }

        return $next($request);
    }
}
