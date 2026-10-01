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
use App\Http\Controllers\Api\V1\AiController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\ApplicationController;
use App\Http\Controllers\Api\V1\AtsDocumentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CsrfTokenController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Controllers\Api\V1\CvController;
use App\Http\Controllers\Api\V1\CvTemplateController;
use App\Http\Controllers\Api\V1\JobsController;
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

    // CV documents (uploads kept for 48 hours) and stateless text extraction
    Route::prefix('cv-documents')->name('cv-documents.')->group(function () {
        Route::get('/', [CvController::class, 'index'])->name('index');
        Route::post('/', [CvController::class, 'store'])->middleware('throttle:10,1,cv:')->name('store');
        Route::post('extract', [CvController::class, 'extract'])->middleware('throttle:20,1,cv-extract:')->name('extract');
        Route::delete('{cv}', [CvController::class, 'destroy'])->whereNumber('cv')->name('destroy');
    });

    // Applications tracker
    Route::prefix('applications')->name('applications.')->group(function () {
        Route::get('/', [ApplicationController::class, 'index'])->name('index');
        Route::post('/', [ApplicationController::class, 'store'])->name('store');
        Route::get('{application}', [ApplicationController::class, 'show'])->whereNumber('application')->name('show');
        Route::match(['PUT', 'PATCH'], '{application}', [ApplicationController::class, 'update'])->whereNumber('application')->name('update');
        Route::delete('{application}', [ApplicationController::class, 'destroy'])->whereNumber('application')->name('destroy');
    });

    // Job search
    Route::prefix('jobs')->name('jobs.')->group(function () {
        Route::post('search', [JobsController::class, 'search'])->middleware('throttle:20,1,jobs-search:')->name('search');
        Route::get('links', [JobsController::class, 'links'])->name('links');
        Route::get('saved-searches', [JobsController::class, 'saved'])->name('saved-searches.index');
        Route::post('saved-searches', [JobsController::class, 'save'])->name('saved-searches.store');
        Route::delete('saved-searches/{search}', [JobsController::class, 'destroySaved'])->whereNumber('search')->name('saved-searches.destroy');
    });

    // AI assistants and the ATS document review (the ATS contract is owned by Phase 3)
    Route::post('ai/chat', [AiController::class, 'chat'])->middleware('throttle:20,1,ai-chat:')->name('ai.chat');
    Route::post('ai/ats-analysis', [AiController::class, 'atsAnalysis'])->middleware('throttle:10,1,ai-ats-analysis:')->name('ai.ats-analysis');
    Route::post('ai/cover-letter', [AiController::class, 'coverLetter'])->middleware('throttle:10,1,ai-cover-letter:')->name('ai.cover-letter');
    Route::post('ats/document', [AtsDocumentController::class, 'analyze'])->middleware('throttle:20,1,ats-document:')->name('ats.document');
});
