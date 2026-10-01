<?php

namespace App\Http\Middleware;

use App\Exceptions\ErrorCode;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Safety net for error responses built by hand (response()->json([...], 4xx))
 * instead of thrown: adds `code` and `request_id` so no API error leaves
 * without them. Existing keys are kept; bodies that already have a `code`,
 * non-JSON bodies and list bodies are not touched.
 */
class EnsureErrorEnvelope
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->is('api/*') || ! $response instanceof JsonResponse || $response->getStatusCode() < 400) {
            return $response;
        }

        $data = $response->getData(true);
        if (! is_array($data) || array_is_list($data) || array_key_exists('code', $data)) {
            return $response;
        }

        $envelope = [];
        foreach ($data as $key => $value) {
            $envelope[$key] = $value;
            if ($key === 'message') {
                $envelope['code'] = ErrorCode::forStatus($response->getStatusCode())->value;
            }
        }
        $envelope['code'] ??= ErrorCode::forStatus($response->getStatusCode())->value;
        $envelope['request_id'] ??= RequestId::current($request) ?? (string) Str::uuid();

        return $response->setData($envelope);
    }
}
