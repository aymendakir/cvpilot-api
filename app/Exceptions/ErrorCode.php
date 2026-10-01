<?php

namespace App\Exceptions;

/**
 * Stable machine-readable error codes (SPEC.md section 4.2). Clients map the
 * code to their own localized text; the default message is for logs and
 * developers and is always safe to return.
 */
enum ErrorCode: string
{
    case BadRequest = 'bad_request';
    case Unauthenticated = 'unauthenticated';
    case InvalidCredentials = 'invalid_credentials';
    case Forbidden = 'forbidden';
    case EmailNotVerified = 'email_not_verified';
    case AccountSuspended = 'account_suspended';
    case NotFound = 'not_found';
    case MethodNotAllowed = 'method_not_allowed';
    case Conflict = 'conflict';
    case PayloadTooLarge = 'payload_too_large';
    case CsrfMismatch = 'csrf_mismatch';
    case ValidationFailed = 'validation_failed';
    case TooManyRequests = 'too_many_requests';
    case ServerError = 'server_error';
    case UpstreamInvalidResponse = 'upstream_invalid_response';
    case UpstreamUnavailable = 'upstream_unavailable';

    public function status(): int
    {
        return match ($this) {
            self::BadRequest => 400,
            self::Unauthenticated, self::InvalidCredentials => 401,
            self::Forbidden, self::EmailNotVerified, self::AccountSuspended => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::Conflict => 409,
            self::PayloadTooLarge => 413,
            self::CsrfMismatch => 419,
            self::ValidationFailed => 422,
            self::TooManyRequests => 429,
            self::ServerError => 500,
            self::UpstreamInvalidResponse => 502,
            self::UpstreamUnavailable => 503,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::BadRequest => 'The request could not be understood.',
            self::Unauthenticated => 'Authentication is required.',
            self::InvalidCredentials => 'Invalid credentials.',
            self::Forbidden => 'You do not have permission to do this.',
            self::EmailNotVerified => 'Verify your email first.',
            self::AccountSuspended => 'This account has been suspended by an administrator. Please contact support.',
            self::NotFound => 'The requested resource was not found.',
            self::MethodNotAllowed => 'This method is not allowed for this endpoint.',
            self::Conflict => 'The request conflicts with the current state.',
            self::PayloadTooLarge => 'The request is too large.',
            self::CsrfMismatch => 'CSRF token mismatch.',
            self::ValidationFailed => 'The given data was invalid.',
            self::TooManyRequests => 'Too many requests. Please wait and try again.',
            self::ServerError => 'Service temporarily unavailable.',
            self::UpstreamInvalidResponse => 'The service could not complete this request. Please retry.',
            self::UpstreamUnavailable => 'The service is temporarily unavailable. Please try again later.',
        };
    }

    /** Server-side failures: their message is never returned to clients, only logged. */
    public function isServerError(): bool
    {
        return $this->status() >= 500;
    }

    /** Nearest documented code for any HTTP status. */
    public static function forStatus(int $status): self
    {
        return match (true) {
            $status === 401 => self::Unauthenticated,
            $status === 403 => self::Forbidden,
            $status === 404 => self::NotFound,
            $status === 405 => self::MethodNotAllowed,
            $status === 409 => self::Conflict,
            $status === 413 => self::PayloadTooLarge,
            $status === 419 => self::CsrfMismatch,
            $status === 422 => self::ValidationFailed,
            $status === 429 => self::TooManyRequests,
            $status === 502 => self::UpstreamInvalidResponse,
            $status === 503, $status === 504 => self::UpstreamUnavailable,
            $status >= 500 => self::ServerError,
            default => self::BadRequest,
        };
    }
}
