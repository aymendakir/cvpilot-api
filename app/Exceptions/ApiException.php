<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * An error with a specific API code. Rendered as the standard envelope by
 * ApiExceptionRenderer. For 5xx codes the message is logged, not returned.
 */
class ApiException extends HttpException
{
    /**
     * @param  array<string, array<int, string>>  $errors  field => messages (validation_failed only)
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        ?string $message = null,
        public readonly array $errors = [],
        array $headers = [],
        ?Throwable $previous = null,
    ) {
        $headers += match ($errorCode) {
            ErrorCode::UpstreamUnavailable => ['Retry-After' => '30'],
            default => [],
        };

        parent::__construct($errorCode->status(), $message ?? $errorCode->message(), $previous, $headers);
    }
}
