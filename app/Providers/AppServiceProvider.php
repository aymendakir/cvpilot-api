<?php

namespace App\Providers;

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
        RateLimiter::for('otp-verify', fn (Request $r) => Limit::perMinute(10)->by('verify:'.$r->ip().'|'.hash('sha256', strtolower(trim((string) $r->input('email'))))));
    }
}
