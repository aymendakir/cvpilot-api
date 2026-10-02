<?php

namespace App\Providers;

use App\Support\ClientIp;
use App\Support\SessionSecurity;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        SessionSecurity::assertSafe($this->app->environment(), config('session.secure'), config('session.same_site'));
        SessionSecurity::assertSameSiteHosts($this->app->environment(), config('session.same_site'), config('session.domain'), [
            'APP_URL' => config('app.url'),
            'FRONTEND_URL' => config('mail.frontend_url'),
            'SITE_URL' => config('site.url'),
        ]);

        // Responses keep their documented top-level shape (no `data` wrapper around a single resource).
        JsonResource::withoutWrapping();

        // Separate buckets prevent an OTP request from exhausting sign-in attempts.
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(300)->by('api:'.($r->session()->get('user_id') ?: $r->ip())));
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(30)->by('login-ip:'.$r->ip()),
            Limit::perMinute(5)->by('login-account:'.hash('sha256', strtolower(trim((string) $r->input('email'))).'|'.$r->ip())),
        ]);
        // Admin routes get their own bucket on top of the shared `api` limiter.
        RateLimiter::for('admin', fn (Request $r) => Limit::perMinute(60)->by('admin:'.($r->session()->get('user_id') ?: $r->ip())));
        RateLimiter::for('register', fn (Request $r) => Limit::perMinute(5)->by('register:'.$r->ip()));
        RateLimiter::for('otp-send', fn (Request $r) => [
            Limit::perMinute(20)->by('otp-ip:'.$r->ip()),
            Limit::perMinutes(10, 3)->by('otp-email:'.hash('sha256', strtolower(trim((string) $r->input('email'))))),
        ]);
        // Anonymous routes (API-A decision D2): per visitor (a keyed hash of the address, never the address
        // itself) per minute and per day, plus one cap on all anonymous ATS checks per day.
        RateLimiter::for('public-ats', function (Request $r) {
            $visitor = ClientIp::hashed($r);
            $limits = [
                Limit::perMinute(max(1, (int) config('anonymous.limits.ats_per_minute')))->by('public-ats-minute:'.$visitor),
                Limit::perDay(max(1, (int) config('anonymous.limits.ats_per_day')))->by('public-ats-day:'.$visitor),
            ];
            $global = (int) config('anonymous.limits.ats_global_per_day');

            return $global > 0 ? [...$limits, Limit::perDay($global)->by('public-ats-all')] : $limits;
        });
        RateLimiter::for('public-extract', fn (Request $r) => [
            Limit::perMinute(max(1, (int) config('anonymous.limits.extract_per_minute')))->by('public-extract-minute:'.ClientIp::hashed($r)),
            Limit::perDay(max(1, (int) config('anonymous.limits.extract_per_day')))->by('public-extract-day:'.ClientIp::hashed($r)),
        ]);
        RateLimiter::for('otp-verify', fn (Request $r) => Limit::perMinute(10)->by('verify:'.$r->ip().'|'.hash('sha256', strtolower(trim((string) $r->input('email'))))));
    }
}
