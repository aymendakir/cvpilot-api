<?php

namespace App\Exceptions;

use App\Http\Middleware\RequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders every exception raised under /api/* as the standard error envelope:
 * { message, code, errors?, request_id }. See SPEC.md section 4 and docs/ERRORS.md.
 */
class ApiExceptionRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        [$code, $message, $errors, $headers] = $this->describe($e);
        $requestId = RequestId::current($request) ?? (string) Str::uuid();

        if ($code->isServerError() && $e instanceof HttpExceptionInterface) {
            // Server-side HTTP errors are not reported by Laravel's handler; keep them in the log.
            Log::error('API server error', [
                'code' => $code->value,
                'status' => $code->status(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'request_id' => $requestId,
            ]);
        }

        $body = ['message' => $message, 'code' => $code->value];
        if ($errors !== []) {
            $body['errors'] = $errors;
        }
        $body['request_id'] = $requestId;

        return new JsonResponse($body, $code->status(), $headers);
    }

    /**
     * @return array{0: ErrorCode, 1: string, 2: array<string, array<int, string>>, 3: array<string, string>}
     */
    private function describe(Throwable $e): array
    {
        if ($e instanceof ApiException) {
            return [$e->errorCode, $this->messageFor($e->errorCode, $e->getMessage()), $e->errors, $e->getHeaders()];
        }

        if ($e instanceof ValidationException) {
            return [ErrorCode::ValidationFailed, $e->getMessage(), $e->errors(), []];
        }

        if ($e instanceof AuthenticationException) {
            return [ErrorCode::Unauthenticated, ErrorCode::Unauthenticated->message(), [], []];
        }

        if ($e instanceof AuthorizationException) {
            return [ErrorCode::Forbidden, ErrorCode::Forbidden->message(), [], []];
        }

        if ($e instanceof TokenMismatchException) {
            return [ErrorCode::CsrfMismatch, ErrorCode::CsrfMismatch->message(), [], []];
        }

        if ($e instanceof ModelNotFoundException) {
            return [ErrorCode::NotFound, ErrorCode::NotFound->message(), [], []];
        }

        if ($e instanceof HttpExceptionInterface) {
            $code = ErrorCode::forStatus($e->getStatusCode());

            return [$code, $this->httpMessage($e, $code), [], $e->getHeaders()];
        }

        return [ErrorCode::ServerError, ErrorCode::ServerError->message(), [], []];
    }

    /** Developer-authored messages pass through for 4xx; 5xx always use the generic text. */
    private function messageFor(ErrorCode $code, ?string $message): string
    {
        if ($code->isServerError() || $message === null || trim($message) === '') {
            return $code->message();
        }

        return $message;
    }

    private function httpMessage(HttpExceptionInterface $e, ErrorCode $code): string
    {
        // Framework-generated messages name the route or method; never return them.
        $frameworkMessage = $e instanceof MethodNotAllowedHttpException
            || ($e instanceof NotFoundHttpException && (
                str_starts_with($e->getMessage(), 'The route ')
                || $e->getPrevious() instanceof ModelNotFoundException
            ))
            || $code === ErrorCode::TooManyRequests
            || $code === ErrorCode::PayloadTooLarge;

        return $frameworkMessage ? $code->message() : $this->messageFor($code, $e->getMessage());
    }
}
