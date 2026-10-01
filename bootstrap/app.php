<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\Admin;
use App\Http\Middleware\EnsureErrorEnvelope;
use App\Http\Middleware\ForceJsonResponses;
use App\Http\Middleware\Member;
use App\Http\Middleware\RejectMalformedJson;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend([RequestId::class, ForceJsonResponses::class, RejectMalformedJson::class]);
        $middleware->validateCsrfTokens(except: ['api/analytics/events', 'api/contact']);
        $middleware->append([EnsureErrorEnvelope::class, SecurityHeaders::class]);
        $middleware->alias(['member' => Member::class, 'admin' => Admin::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(new ApiExceptionRenderer);
    })
    ->create();
