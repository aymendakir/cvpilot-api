<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\Admin;
use App\Http\Middleware\AuditAdmin;
use App\Http\Middleware\AuthSession;
use App\Http\Middleware\EnsureErrorEnvelope;
use App\Http\Middleware\EnsurePublicAiEnabled;
use App\Http\Middleware\ForceJsonResponses;
use App\Http\Middleware\RejectMalformedJson;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustHosts;
use App\Http\Middleware\VerifyTurnstile;
use App\Support\TrustedProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')->prefix('api')->group(function () {
                Route::group([], base_path('routes/oauth.php'));
            });
            Route::middleware('web')->prefix('api/v1')->name('v1.')->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // TrustHosts first: nothing may read the Host header before it is checked (API-B).
        $middleware->prepend([TrustHosts::class, RequestId::class, ForceJsonResponses::class, RejectMalformedJson::class]);
        // Behind a TLS proxy (Sevalla, Cloudflare, Caddy) the proxy's forwarded headers must be trusted for HTTPS detection and HSTS.
        $middleware->trustProxies(at: TrustedProxies::parse(env('TRUSTED_PROXIES')));
        $middleware->validateCsrfTokens(except: ['api/v1/analytics/events', 'api/v1/contact-messages']);
        $middleware->append([EnsureErrorEnvelope::class, SecurityHeaders::class]);
        // Authenticate (member, admin) before route-model binding, so an anonymous request for a
        // missing id gets 401 instead of a 404 that reveals which ids exist.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: Admin::class);
        $middleware->prependToPriorityList(before: Admin::class, prepend: AuthSession::class);
        $middleware->alias([
            'auth.session' => AuthSession::class,
            'admin' => Admin::class,
            'admin.audit' => AuditAdmin::class,
            'turnstile' => VerifyTurnstile::class,
            'public-ai' => EnsurePublicAiEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(new ApiExceptionRenderer);
    })
    ->create();
