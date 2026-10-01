<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A JSON request whose body is not valid JSON is a 400, not an empty input. */
class RejectMalformedJson
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/*') && $request->isJson()) {
            $content = $request->getContent();

            if ($content !== '' && ! $this->isValidJson($content)) {
                throw new ApiException(ErrorCode::BadRequest);
            }
        }

        return $next($request);
    }

    private function isValidJson(string $content): bool
    {
        json_decode($content);

        return json_last_error() === JSON_ERROR_NONE;
    }
}
