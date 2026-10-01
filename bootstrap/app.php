<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\Admin;
use App\Http\Middleware\Deprecated;
use App\Http\Middleware\EnsureErrorEnvelope;
use App\Http\Middleware\ForceJsonResponses;
use App\Http\Middleware\Member;
use App\Http\Middleware\RejectMalformedJson;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        health: '/up',
        then: function () {
            Route::middleware('web')->prefix('api')->group(function () {
                Route::middleware('deprecated')->group(base_path('routes/legacy.php'));
                Route::group([], base_path('routes/oauth.php'));
            });
            Route::middleware('web')->prefix('api/v1')->name('v1.')->group(base_path('routes/api.php'));

            // Legacy routes get stable names: legacy.<method>.<uri without the api/ prefix>.
            foreach (Route::getRoutes() as $route) {
                if ($route->getName() === null && str_starts_with($route->uri(), 'api/') && ! str_starts_with($route->uri(), 'api/v1/')) {
                    $uri = trim(str_replace(['/', '{', '}'], ['.', '', ''], substr($route->uri(), 4)), '.');
                    $route->name('legacy.'.strtolower($route->methods()[0]).'.'.$uri);
                }
            }
            Route::getRoutes()->refreshNameLookups();
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend([RequestId::class, ForceJsonResponses::class, RejectMalformedJson::class]);
        $middleware->validateCsrfTokens(except: ['api/analytics/events', 'api/contact']);
        $middleware->append([EnsureErrorEnvelope::class, SecurityHeaders::class]);
        $middleware->alias([
            'member' => Member::class,
            'auth.session' => Member::class,
            'admin' => Admin::class,
            'deprecated' => Deprecated::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(new ApiExceptionRenderer);
    })
    ->create();
