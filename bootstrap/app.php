<?php

use App\Http\Middleware\Admin;
use App\Http\Middleware\ForceJsonResponses;
use App\Http\Middleware\Member;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend([RequestId::class, ForceJsonResponses::class]);
        $middleware->validateCsrfTokens(except: ['api/analytics/events', 'api/contact']);
        $middleware->append(SecurityHeaders::class);
        $middleware->alias(['member' => Member::class, 'admin' => Admin::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') && ! $e instanceof ValidationException) {
                $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
                if ($status >= 500) {
                    return response()->json(['message' => 'Service temporarily unavailable.'], 500);
                }
            }
        });
    })
    ->create();
