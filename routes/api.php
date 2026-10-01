<?php

/*
 * Canonical API routes, served under /api/v1 (see bootstrap/app.php, which
 * adds the `web` middleware group, the /api/v1 prefix and the `v1.` name
 * prefix).
 *
 * Conventions (SPEC.md section 3): plural kebab-case nouns, HTTP verbs for
 * actions, no closures, ids constrained with whereNumber(), every route named.
 * Throttle bucket prefixes (`max,minutes,prefix:`) name the counter each limiter uses.
 */

use App\Http\Controllers\Api\V1\AccountDataController;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\Admin\BlogPostController;
use App\Http\Controllers\Api\V1\Admin\CacheController;
use App\Http\Controllers\Api\V1\Admin\IntegrationController;
use App\Http\Controllers\Api\V1\Admin\MailSettingsController;
use App\Http\Controllers\Api\V1\AiController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\ApplicationController;
use App\Http\Controllers\Api\V1\AtsDocumentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BlogController;
use App\Http\Controllers\Api\V1\CareerAiController;
use App\Http\Controllers\Api\V1\CsrfTokenController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Controllers\Api\V1\CvController;
use App\Http\Controllers\Api\V1\CvTemplateController;
use App\Http\Controllers\Api\V1\CvVersionController;
use App\Http\Controllers\Api\V1\InsightsController;
use App\Http\Controllers\Api\V1\InterviewController;
use App\Http\Controllers\Api\V1\JobsController;
use App\Http\Controllers\Api\V1\JobWorkspaceController;
use App\Http\Controllers\Api\V1\LibraryController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SiteSettingsController;
use App\Http\Controllers\Api\V1\SupportController;
use App\Models\BlogPost;
use Illuminate\Support\Facades\Route;

// Public
Route::get('csrf', CsrfTokenController::class)->name('csrf');
Route::get('site-settings', [SiteSettingsController::class, 'show'])->name('site-settings.show');
Route::post('contact-messages', [SupportController::class, 'store'])->middleware('throttle:3,10,contact:')->name('contact-messages.store');
Route::post('analytics/events', [AnalyticsController::class, 'store'])->middleware('throttle:120,1,analytics-events:')->name('analytics.events.store');
Route::get('cv-templates', [CvTemplateController::class, 'published'])->middleware('throttle:60,1,cv-templates:')->name('cv-templates.index');

// Public blog: cacheable (Cache-Control in SecurityHeaders), so no session and no cookies on these two routes.
Route::withoutMiddleware('web')->middleware('throttle:60,1,blog:')->group(function () {
    Route::get('blog', [BlogController::class, 'index'])->name('blog.index');
    Route::get('blog/{slug}', [BlogController::class, 'show'])->where('slug', BlogPost::SLUG_PATTERN)->name('blog.show');
});

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
        Route::get('dashboard', [InsightsController::class, 'dashboard'])->name('dashboard');
        Route::get('analytics', [InsightsController::class, 'analytics'])->name('analytics');
    });

    // CV documents (extracted text and metadata kept for 48 hours; the original file is never stored) and stateless text extraction
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

    // Career workspace: saved CV versions, job workspaces, reports, mock interviews and the library
    Route::prefix('cv-versions')->name('cv-versions.')->group(function () {
        Route::get('/', [CvVersionController::class, 'index'])->name('index');
        Route::post('/', [CvVersionController::class, 'store'])->name('store');
        Route::get('{version}', [CvVersionController::class, 'show'])->whereNumber('version')->name('show');
        Route::patch('{version}', [CvVersionController::class, 'update'])->whereNumber('version')->name('update');
        Route::delete('{version}', [CvVersionController::class, 'destroy'])->whereNumber('version')->name('destroy');
    });
    Route::prefix('job-workspaces')->name('job-workspaces.')->group(function () {
        Route::get('/', [JobWorkspaceController::class, 'index'])->name('index');
        Route::post('/', [JobWorkspaceController::class, 'store'])->name('store');
        Route::get('{workspace}', [JobWorkspaceController::class, 'show'])->whereNumber('workspace')->name('show');
    });
    Route::delete('reports/{report}', [ReportController::class, 'destroy'])->whereNumber('report')->name('reports.destroy');
    Route::get('library', LibraryController::class)->name('library');
    Route::prefix('interviews')->name('interviews.')->group(function () {
        Route::post('/', [InterviewController::class, 'store'])->middleware('throttle:10,1,career-interviews:')->name('store');
        Route::get('{session}', [InterviewController::class, 'show'])->whereNumber('session')->name('show');
        Route::delete('{session}', [InterviewController::class, 'destroy'])->whereNumber('session')->name('destroy');
        Route::post('{session}/reply', [InterviewController::class, 'reply'])->whereNumber('session')->middleware('throttle:20,1,career-interviews--session--reply:')->name('reply');
        Route::post('{session}/finish', [InterviewController::class, 'finish'])->whereNumber('session')->middleware('throttle:5,1,career-interviews--session--finish:')->name('finish');
    });
    Route::post('ai/recruiter-view', [CareerAiController::class, 'recruiterView'])->middleware('throttle:10,1,career-recruiter-view:')->name('ai.recruiter-view');
    Route::post('ai/tailor-cv', [CareerAiController::class, 'tailorCv'])->middleware('throttle:8,1,career-tailor-cv:')->name('ai.tailor-cv');
    Route::post('ai/application-pack', [CareerAiController::class, 'applicationPack'])->middleware('throttle:6,1,career-application-pack:')->name('ai.application-pack');
    Route::post('ai/skill-gap', [CareerAiController::class, 'skillGap'])->middleware('throttle:10,1,career-skill-gap:')->name('ai.skill-gap');
    Route::post('ai/portfolio-review', [CareerAiController::class, 'portfolio'])->middleware('throttle:10,1,career-portfolio:')->name('ai.portfolio-review');
    Route::post('ai/follow-up', [CareerAiController::class, 'followUp'])->middleware('throttle:10,1,career-follow-up:')->name('ai.follow-up');
    Route::post('ai/career-diagnostic', [CareerAiController::class, 'diagnostic'])->middleware('throttle:5,1,career-diagnostic:')->name('ai.career-diagnostic');

    // Administration (admin role required)
    Route::prefix('admin')->name('admin.')->middleware(['admin', 'throttle:admin', 'admin.audit'])->group(function () {
        Route::get('site-settings', [SiteSettingsController::class, 'show'])->name('site-settings.show');
        Route::put('site-settings', [SiteSettingsController::class, 'save'])->name('site-settings.update');
        Route::get('system', [SiteSettingsController::class, 'system'])->name('system');
        Route::get('summary', [AdminController::class, 'summary'])->name('summary');
        Route::get('analytics', [AnalyticsController::class, 'report'])->name('analytics');
        Route::get('audit-events', [AdminController::class, 'logs'])->name('audit-events.index');
        Route::get('applications', [AdminController::class, 'applications'])->name('applications.index');
        Route::delete('cache', [CacheController::class, 'destroy'])->name('cache.destroy');

        Route::get('contact-messages', [SupportController::class, 'index'])->name('contact-messages.index');
        Route::patch('contact-messages/{message}', [SupportController::class, 'update'])->whereNumber('message')->name('contact-messages.update');

        Route::prefix('cv-templates')->name('cv-templates.')->group(function () {
            Route::get('/', [CvTemplateController::class, 'index'])->name('index');
            Route::post('/', [CvTemplateController::class, 'store'])->name('store');
            Route::patch('{template}', [CvTemplateController::class, 'update'])->whereNumber('template')->name('update');
            Route::delete('{template}', [CvTemplateController::class, 'destroy'])->whereNumber('template')->name('destroy');
        });

        Route::prefix('smtp')->name('smtp.')->group(function () {
            Route::get('/', [MailSettingsController::class, 'show'])->name('show');
            Route::put('/', [MailSettingsController::class, 'save'])->name('update');
            Route::post('microsoft/connect', [MailSettingsController::class, 'connectMicrosoft'])->middleware('throttle:5,1,smtp-connect:')->name('microsoft.connect');
            Route::post('check', [MailSettingsController::class, 'check'])->middleware('throttle:3,1,smtp-check:')->name('check');
            Route::post('test', [MailSettingsController::class, 'test'])->middleware('throttle:3,1,smtp-test:')->name('test');
        });

        Route::prefix('blog')->name('blog.')->group(function () {
            Route::get('/', [BlogPostController::class, 'index'])->name('index');
            Route::post('/', [BlogPostController::class, 'store'])->name('store');
            Route::get('{post}', [BlogPostController::class, 'show'])->whereNumber('post')->name('show');
            Route::put('{post}', [BlogPostController::class, 'update'])->whereNumber('post')->name('update');
            Route::delete('{post}', [BlogPostController::class, 'destroy'])->whereNumber('post')->name('destroy');
        });

        Route::prefix('users')->name('users.')->group(function () {
            Route::get('/', [AdminController::class, 'users'])->name('index');
            Route::post('/', [AdminController::class, 'storeUser'])->middleware('throttle:10,1,admin-create-user:')->name('store');
            Route::get('{user}', [AdminController::class, 'detail'])->whereNumber('user')->name('show');
            Route::patch('{user}', [AdminController::class, 'suspend'])->whereNumber('user')->name('update');
            Route::post('{user}/warnings', [AdminController::class, 'storeWarning'])->whereNumber('user')->middleware('throttle:10,1,warning:')->name('warnings.store');
        });

        Route::prefix('integrations')->name('integrations.')->group(function () {
            Route::get('/', [IntegrationController::class, 'index'])->name('index');
            Route::post('/', [IntegrationController::class, 'store'])->name('store');
            Route::post('reorder', [IntegrationController::class, 'reorder'])->name('reorder');
            Route::patch('{integration}', [IntegrationController::class, 'update'])->whereNumber('integration')->name('update');
            Route::post('{integration}/test', [IntegrationController::class, 'test'])->whereNumber('integration')->middleware('throttle:10,1,integrations--integration--test:')->name('test');
            Route::delete('{integration}', [IntegrationController::class, 'destroy'])->whereNumber('integration')->name('destroy');
        });
    });
});
