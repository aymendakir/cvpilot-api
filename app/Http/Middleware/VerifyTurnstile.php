<?php

namespace App\Http\Middleware;

use App\Exceptions\UpstreamUnavailableException;
use App\Support\ClientIp;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cloudflare Turnstile on the anonymous routes (API-A). The token comes from the widget's own form field
 * (`cf-turnstile-response`, so a plain HTML form works) or the `CF-Turnstile-Response` header. It is
 * checked once with Cloudflare before the upload is read. `errors.turnstile` carries a token, like
 * `errors.file`: `missing` or `failed`. Cloudflare unreachable: 503. No secret: skipped in local and
 * testing, 503 everywhere else, so a missing secret never leaves the routes open.
 */
class VerifyTurnstile
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('anonymous.turnstile.secret');
        if ($secret === '') {
            if (app()->environment(['local', 'testing'])) {
                return $next($request);
            }
            throw new UpstreamUnavailableException('Turnstile secret is not configured.');
        }

        $token = $request->input('cf-turnstile-response') ?? $request->header('CF-Turnstile-Response');
        if (! is_string($token) || $token === '' || strlen($token) > 2048) {
            throw ValidationException::withMessages(['turnstile' => ['missing']]);
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('anonymous.turnstile.timeout'))
                ->post((string) config('anonymous.turnstile.verify_url'), [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => ClientIp::of($request),
                ]);
        } catch (ConnectionException $e) {
            throw new UpstreamUnavailableException('Turnstile verification is unreachable.', $e);
        }
        if (! $response->successful()) {
            throw new UpstreamUnavailableException('Turnstile verification answered HTTP '.$response->status().'.');
        }

        $hostnames = (array) config('anonymous.turnstile.hostnames');
        $passed = $response->json('success') === true
            && ($hostnames === [] || in_array($response->json('hostname'), $hostnames, true));
        if (! $passed) {
            // Cloudflare's error codes only (never the token or the secret).
            Log::info('turnstile.failed', ['codes' => (array) $response->json('error-codes', [])]);

            throw ValidationException::withMessages(['turnstile' => ['failed']]);
        }

        return $next($request);
    }
}
