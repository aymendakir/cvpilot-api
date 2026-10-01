<?php

namespace App\Exceptions;

use Throwable;

/**
 * A provider answered, but with an error or output we cannot use (for
 * example an unparseable review). Rendered as 502 upstream_invalid_response;
 * the message is logged, never returned.
 */
class UpstreamInvalidResponseException extends ApiException
{
    public function __construct(?string $message = null, ?Throwable $previous = null)
    {
        parent::__construct(ErrorCode::UpstreamInvalidResponse, $message, previous: $previous);
    }
}
