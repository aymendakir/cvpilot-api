<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an id, exposes it to application code and to Laravel's log context, and
 * returns it in the X-Request-Id response header.
 */
class RequestId
{
    public const HEADER = 'X-Request-Id';

    private const INBOUND_PATTERN = '/^[A-Za-z0-9._-]{8,64}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $id = $this->resolve($request);

        $request->attributes->set('request_id', $id);
        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    public static function current(?Request $request = null): ?string
    {
        $id = ($request ?? request())->attributes->get('request_id');

        return is_string($id) ? $id : null;
    }

    private function resolve(Request $request): string
    {
        $inbound = $request->headers->get(self::HEADER);

        if (is_string($inbound) && preg_match(self::INBOUND_PATTERN, $inbound) === 1) {
            return $inbound;
        }

        return (string) Str::uuid();
    }
}
