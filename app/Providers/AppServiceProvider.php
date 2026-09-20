<?php
namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Separate buckets prevent an OTP request from exhausting sign-in attempts.
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(180)->by('api:'.($r->session()->get('user_id') ?: $r->ip())));
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(60)->by('login-ip:'.$r->ip()),
            Limit::perMinute(8)->by('login-account:'.hash('sha256', strtolower(trim((string) $r->input('email'))).'|'.$r->ip())),
        ]);
        RateLimiter::for('register', fn (Request $r) => Limit::perMinute(5)->by('register:'.$r->ip()));
        RateLimiter::for('otp-send', fn (Request $r) => [
            Limit::perMinute(20)->by('otp-ip:'.$r->ip()),
            Limit::perMinutes(10, 3)->by('otp-email:'.hash('sha256', strtolower(trim((string) $r->input('email'))))),
        ]);
        RateLimiter::for('otp-verify', fn (Request $r) => Limit::perMinute(10)->by('verify:'.$r->ip().'|'.hash('sha256', strtolower(trim((string) $r->input('email'))))));
    }
}
