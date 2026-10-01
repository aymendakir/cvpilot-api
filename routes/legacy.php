<?php

/*
 * Legacy API routes (deprecated aliases, removed in slice S7).
 *
 * Loaded by bootstrap/app.php inside Route::middleware('web')->prefix('api').
 * Every route carries exactly one `deprecated` middleware: `deprecated:<successor path>`
 * when a /api/v1 replacement exists, bare `deprecated` for legacy-only routes.
 * Every path here keeps its pre-/api/v1 behavior; canonical routes live in routes/api.php.
 * Routes are named legacy.<method>.<uri> automatically (see bootstrap/app.php).
 */

use App\Http\Controllers\Api\V1\AccountDataController;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\Admin\CacheController;
use App\Http\Controllers\Api\V1\Admin\IntegrationController;
use App\Http\Controllers\Api\V1\Admin\MailSettingsController;
use App\Http\Controllers\Api\V1\AiController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\ApplicationController;
use App\Http\Controllers\Api\V1\AtsDocumentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CareerController;
use App\Http\Controllers\Api\V1\CsrfTokenController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Controllers\Api\V1\CvController;
use App\Http\Controllers\Api\V1\CvTemplateController;
use App\Http\Controllers\Api\V1\JobsController;
use App\Http\Controllers\Api\V1\SiteSettingsController;
use App\Http\Controllers\Api\V1\SupportController;
use Illuminate\Support\Facades\Route;

Route::get('csrf', CsrfTokenController::class)->middleware('deprecated:/api/v1/csrf');
Route::get('site-settings', [SiteSettingsController::class, 'show'])->middleware('deprecated:/api/v1/site-settings');
Route::post('contact', [SupportController::class, 'store'])->middleware('throttle:3,10,contact:')->middleware('deprecated:/api/v1/contact-messages');
Route::post('analytics/events', [AnalyticsController::class, 'store'])->middleware('throttle:120,1,analytics-events:')->middleware('deprecated:/api/v1/analytics/events');
Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register')->middleware('deprecated:/api/v1/auth/register');
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->middleware('deprecated:/api/v1/auth/login');
Route::post('otp/request', [AuthController::class, 'code'])->middleware('throttle:otp-send')->middleware('deprecated:/api/v1/auth/otp/request');
Route::post('otp/verify', [AuthController::class, 'verify'])->middleware('throttle:otp-verify')->middleware('deprecated:/api/v1/auth/otp/verify');
Route::get('cv-templates', [CvTemplateController::class, 'published'])->middleware('throttle:60,1,cv-templates:')->middleware('deprecated:/api/v1/cv-templates');
Route::middleware(['throttle:api', 'member'])->group(function () {
    Route::get('me/export', [AccountDataController::class, 'export'])->middleware('throttle:3,1,account-export:')->middleware('deprecated:/api/v1/me/export');
    Route::delete('me', [AccountDataController::class, 'destroy'])->middleware('throttle:3,10,account-delete:')->middleware('deprecated:/api/v1/me');
    Route::get('me', CurrentUserController::class)->middleware('deprecated:/api/v1/me');
    Route::patch('me', [AuthController::class, 'profile'])->middleware('deprecated:/api/v1/me');
    Route::post('password', [AuthController::class, 'password'])->middleware('deprecated:/api/v1/me/password');
    Route::post('logout', [AuthController::class, 'logoutLegacy'])->middleware('deprecated:/api/v1/auth/logout');
    Route::post('jobs/search', [JobsController::class, 'search'])->middleware('throttle:20,1,jobs-search:')->middleware('deprecated');
    Route::get('jobs/links', [JobsController::class, 'links'])->middleware('deprecated');
    Route::get('jobs/saved-searches', [JobsController::class, 'saved'])->middleware('deprecated');
    Route::post('jobs/saved-searches', [JobsController::class, 'save'])->middleware('deprecated');
    Route::delete('jobs/saved-searches/{search}', [JobsController::class, 'destroySaved'])->middleware('deprecated');
    Route::get('cv', [CvController::class, 'index'])->middleware('deprecated');
    Route::post('cv', [CvController::class, 'store'])->middleware('throttle:10,1,cv:')->middleware('deprecated');
    Route::post('cv/extract', [CvController::class, 'extract'])->middleware('throttle:20,1,cv-extract:')->middleware('deprecated');
    Route::post('cv/{cv}/analyze', [CvController::class, 'analyze'])->middleware('deprecated');
    Route::delete('cv/{cv}', [CvController::class, 'destroy'])->middleware('deprecated');
    Route::apiResource('applications', ApplicationController::class)->except(['show'])->names('legacy.applications')->middleware('deprecated');
    Route::get('career/library', [CareerController::class, 'library'])->middleware('deprecated');
    Route::get('career/interviews/{session}', [CareerController::class, 'interview'])->middleware('deprecated');
    Route::get('career/workspaces/{workspace}', [CareerController::class, 'workspace'])->middleware('deprecated');
    Route::get('career/cv-versions/{version}', [CareerController::class, 'version'])->middleware('deprecated');
    Route::patch('career/cv-versions/{version}', [CareerController::class, 'updateVersion'])->middleware('deprecated');
    Route::get('career/dashboard', [CareerController::class, 'dashboard'])->middleware('deprecated');
    Route::get('career/workspaces', [CareerController::class, 'workspaces'])->middleware('deprecated');
    Route::post('career/workspaces', [CareerController::class, 'storeWorkspace'])->middleware('deprecated');
    Route::get('career/cv-versions', [CareerController::class, 'versions'])->middleware('deprecated');
    Route::post('career/cv-versions', [CareerController::class, 'storeVersion'])->middleware('deprecated');
    Route::delete('career/cv-versions/{version}', [CareerController::class, 'destroyVersion'])->middleware('deprecated');
    Route::get('career/analytics', [CareerController::class, 'analytics'])->middleware('deprecated');
    Route::post('career/recruiter-view', [CareerController::class, 'recruiterView'])->middleware('throttle:10,1,career-recruiter-view:')->middleware('deprecated');
    Route::post('career/tailor-cv', [CareerController::class, 'tailorCv'])->middleware('throttle:8,1,career-tailor-cv:')->middleware('deprecated');
    Route::post('career/application-pack', [CareerController::class, 'applicationPack'])->middleware('throttle:6,1,career-application-pack:')->middleware('deprecated');
    Route::post('career/skill-gap', [CareerController::class, 'skillGap'])->middleware('throttle:10,1,career-skill-gap:')->middleware('deprecated');
    Route::post('career/portfolio', [CareerController::class, 'portfolio'])->middleware('throttle:10,1,career-portfolio:')->middleware('deprecated');
    Route::post('career/follow-up', [CareerController::class, 'followUp'])->middleware('throttle:10,1,career-follow-up:')->middleware('deprecated');
    Route::post('career/diagnostic', [CareerController::class, 'diagnostic'])->middleware('throttle:5,1,career-diagnostic:')->middleware('deprecated');
    Route::delete('career/reports/{report}', [CareerController::class, 'destroyReport'])->middleware('deprecated');
    Route::delete('career/interviews/{session}', [CareerController::class, 'destroyInterview'])->middleware('deprecated');
    Route::post('career/interviews', [CareerController::class, 'startInterview'])->middleware('throttle:10,1,career-interviews:')->middleware('deprecated');
    Route::post('career/interviews/{session}/reply', [CareerController::class, 'replyInterview'])->middleware('throttle:20,1,career-interviews--session--reply:')->middleware('deprecated');
    Route::post('career/interviews/{session}/finish', [CareerController::class, 'finishInterview'])->middleware('throttle:5,1,career-interviews--session--finish:')->middleware('deprecated');
    Route::post('ai/chat', [AiController::class, 'chat'])->middleware('throttle:20,1,ai-chat:')->middleware('deprecated');
    Route::post('ai/improve-cv', [AiController::class, 'improveCv'])->middleware('throttle:10,1,ai-improve-cv:')->middleware('deprecated');
    Route::post('ai/ats-analysis', [AiController::class, 'atsAnalysis'])->middleware('throttle:10,1,ai-ats-analysis:')->middleware('deprecated');
    Route::post('ai/cover-letter', [AiController::class, 'coverLetter'])->middleware('throttle:10,1,ai-cover-letter:')->middleware('deprecated');
    Route::post('ats/document', [AtsDocumentController::class, 'analyze'])->middleware('throttle:20,1,ats-document:')->middleware('deprecated');
    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('site-settings', [SiteSettingsController::class, 'show'])->middleware('deprecated:/api/v1/site-settings');
        Route::put('site-settings', [SiteSettingsController::class, 'save'])->middleware('deprecated:/api/v1/site-settings');
        Route::get('system', [SiteSettingsController::class, 'system'])->middleware('deprecated');
        Route::get('contact-messages', [SupportController::class, 'index'])->middleware('deprecated');
        Route::patch('contact-messages/{message}', [SupportController::class, 'update'])->middleware('deprecated');
        Route::get('summary', [AdminController::class, 'summary'])->middleware('deprecated');
        Route::get('cv-templates', [CvTemplateController::class, 'index'])->middleware('deprecated:/api/v1/cv-templates');
        Route::post('cv-templates', [CvTemplateController::class, 'store'])->middleware('deprecated:/api/v1/cv-templates');
        Route::patch('cv-templates/{template}', [CvTemplateController::class, 'update'])->middleware('deprecated');
        Route::delete('cv-templates/{template}', [CvTemplateController::class, 'destroy'])->middleware('deprecated');
        Route::get('smtp', [MailSettingsController::class, 'show'])->middleware('deprecated');
        Route::post('smtp/microsoft/connect', [MailSettingsController::class, 'connectMicrosoft'])->middleware('throttle:5,1,smtp-connect:')->middleware('deprecated');
        Route::put('smtp', [MailSettingsController::class, 'save'])->middleware('deprecated');
        Route::post('smtp/check', [MailSettingsController::class, 'check'])->middleware('throttle:3,1,smtp-check:')->middleware('deprecated');
        Route::post('smtp/test', [MailSettingsController::class, 'test'])->middleware('throttle:3,1,smtp-test:')->middleware('deprecated');
        Route::get('users', [AdminController::class, 'users'])->middleware('deprecated');
        Route::get('users/{user}', [AdminController::class, 'detail'])->middleware('deprecated');
        Route::post('users/{user}/warning', [AdminController::class, 'warning'])->middleware('throttle:10,1,warning:')->middleware('deprecated');
        Route::get('applications', [AdminController::class, 'applications'])->middleware('deprecated');
        Route::get('uploads/{cv}', [AdminController::class, 'download'])->middleware('deprecated');
        Route::patch('users/{user}', [AdminController::class, 'suspend'])->middleware('deprecated');
        Route::get('logs', [AdminController::class, 'logs'])->middleware('deprecated');
        Route::get('analytics', [AnalyticsController::class, 'report'])->middleware('deprecated');
        Route::get('integrations', [IntegrationController::class, 'index'])->middleware('deprecated');
        Route::post('integrations', [IntegrationController::class, 'store'])->middleware('deprecated');
        Route::post('integrations/reorder', [IntegrationController::class, 'reorder'])->middleware('deprecated');
        Route::patch('integrations/{integration}', [IntegrationController::class, 'update'])->middleware('deprecated');
        Route::post('integrations/{integration}/test', [IntegrationController::class, 'test'])->middleware('throttle:10,1,integrations--integration--test:')->middleware('deprecated');
        Route::delete('integrations/{integration}', [IntegrationController::class, 'destroy'])->middleware('deprecated');
        Route::post('cache/clear', [CacheController::class, 'clear'])->middleware('deprecated');
    });
});
