<?php

/*
 * Canonical API routes, served under /api/v1 (see bootstrap/app.php, which
 * adds the `web` middleware group, the /api/v1 prefix and the `v1.` name
 * prefix). Controllers are shared with the deprecated routes in routes/legacy.php.
 *
 * Conventions (SPEC.md section 3): plural kebab-case nouns, HTTP verbs for
 * actions, no closures, ids constrained with whereNumber(), every route named.
 * Throttle bucket prefixes match the legacy routes so both paths share one counter.
 */

use App\Http\Controllers\Api\V1\AccountDataController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CsrfTokenController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Controllers\Api\V1\CvTemplateController;
use App\Http\Controllers\Api\V1\SiteSettingsController;
use App\Http\Controllers\Api\V1\SupportController;
use Illuminate\Support\Facades\Route;

// Public
Route::get('csrf', CsrfTokenController::class)->name('csrf');
Route::get('site-settings', [SiteSettingsController::class, 'show'])->name('site-settings.show');
Route::post('contact-messages', [SupportController::class, 'store'])->middleware('throttle:3,10,contact:')->name('contact-messages.store');
Route::post('analytics/events', [AnalyticsController::class, 'store'])->middleware('throttle:120,1,analytics-events:')->name('analytics.events.store');
Route::get('cv-templates', [CvTemplateController::class, 'published'])->middleware('throttle:60,1,cv-templates:')->name('cv-templates.index');

// Authentication
Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register')->name('register');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');
    Route::post('otp/request', [AuthController::class, 'code'])->middleware('throttle:otp-send')->name('otp.request');
    Route::post('otp/verify', [AuthController::class, 'verify'])->middleware('throttle:otp-verify')->name('otp.verify');
});

// Signed-in member
Route::middleware(['throttle:api', 'auth.session'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

    Route::prefix('me')->name('me.')->group(function () {
        Route::get('/', CurrentUserController::class)->name('show');
        Route::patch('/', [AuthController::class, 'profile'])->name('update');
        Route::delete('/', [AccountDataController::class, 'destroy'])->middleware('throttle:3,10,account-delete:')->name('destroy');
        Route::get('export', [AccountDataController::class, 'export'])->middleware('throttle:3,1,account-export:')->name('export');
        Route::put('password', [AuthController::class, 'password'])->name('password.update');
    });
});
