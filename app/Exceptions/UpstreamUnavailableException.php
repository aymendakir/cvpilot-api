<?php

namespace App\Exceptions;

use Throwable;

/**
 * A provider (AI, job board, mail) is unreachable, timed out, or not
 * configured. Rendered as 503 upstream_unavailable with Retry-After; the
 * message is logged, never returned.
 */
class UpstreamUnavailableException extends ApiException
{
    public function __construct(?string $message = null, ?Throwable $previous = null)
    {
        parent::__construct(ErrorCode::UpstreamUnavailable, $message, previous: $previous);
    }
}
