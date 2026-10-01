<?php

/*
 * Legacy API routes (deprecated aliases, removed in slice S7).
 *
 * Loaded by bootstrap/app.php inside Route::middleware(['web', 'deprecated'])->prefix('api').
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
    Route::post('jobs/search', [JobsController::class, 'search'])->middleware('throttle:20,1,jobs-search:');
    Route::get('jobs/links', [JobsController::class, 'links']);
    Route::get('jobs/saved-searches', [JobsController::class, 'saved']);
    Route::post('jobs/saved-searches', [JobsController::class, 'save']);
    Route::delete('jobs/saved-searches/{search}', [JobsController::class, 'destroySaved']);
    Route::get('cv', [CvController::class, 'index']);
    Route::post('cv', [CvController::class, 'store'])->middleware('throttle:10,1,cv:');
    Route::post('cv/extract', [CvController::class, 'extract'])->middleware('throttle:20,1,cv-extract:');
    Route::post('cv/{cv}/analyze', [CvController::class, 'analyze']);
    Route::delete('cv/{cv}', [CvController::class, 'destroy']);
    Route::apiResource('applications', ApplicationController::class)->except(['show'])->names('legacy.applications');
    Route::get('career/library', [CareerController::class, 'library']);
    Route::get('career/interviews/{session}', [CareerController::class, 'interview']);
    Route::get('career/workspaces/{workspace}', [CareerController::class, 'workspace']);
    Route::get('career/cv-versions/{version}', [CareerController::class, 'version']);
    Route::patch('career/cv-versions/{version}', [CareerController::class, 'updateVersion']);
    Route::get('career/dashboard', [CareerController::class, 'dashboard']);
    Route::get('career/workspaces', [CareerController::class, 'workspaces']);
    Route::post('career/workspaces', [CareerController::class, 'storeWorkspace']);
    Route::get('career/cv-versions', [CareerController::class, 'versions']);
    Route::post('career/cv-versions', [CareerController::class, 'storeVersion']);
    Route::delete('career/cv-versions/{version}', [CareerController::class, 'destroyVersion']);
    Route::get('career/analytics', [CareerController::class, 'analytics']);
    Route::post('career/recruiter-view', [CareerController::class, 'recruiterView'])->middleware('throttle:10,1,career-recruiter-view:');
    Route::post('career/tailor-cv', [CareerController::class, 'tailorCv'])->middleware('throttle:8,1,career-tailor-cv:');
    Route::post('career/application-pack', [CareerController::class, 'applicationPack'])->middleware('throttle:6,1,career-application-pack:');
    Route::post('career/skill-gap', [CareerController::class, 'skillGap'])->middleware('throttle:10,1,career-skill-gap:');
    Route::post('career/portfolio', [CareerController::class, 'portfolio'])->middleware('throttle:10,1,career-portfolio:');
    Route::post('career/follow-up', [CareerController::class, 'followUp'])->middleware('throttle:10,1,career-follow-up:');
    Route::post('career/diagnostic', [CareerController::class, 'diagnostic'])->middleware('throttle:5,1,career-diagnostic:');
    Route::delete('career/reports/{report}', [CareerController::class, 'destroyReport']);
    Route::delete('career/interviews/{session}', [CareerController::class, 'destroyInterview']);
    Route::post('career/interviews', [CareerController::class, 'startInterview'])->middleware('throttle:10,1,career-interviews:');
    Route::post('career/interviews/{session}/reply', [CareerController::class, 'replyInterview'])->middleware('throttle:20,1,career-interviews--session--reply:');
    Route::post('career/interviews/{session}/finish', [CareerController::class, 'finishInterview'])->middleware('throttle:5,1,career-interviews--session--finish:');
    Route::post('ai/chat', [AiController::class, 'chat'])->middleware('throttle:20,1,ai-chat:');
    Route::post('ai/improve-cv', [AiController::class, 'improveCv'])->middleware('throttle:10,1,ai-improve-cv:');
    Route::post('ai/ats-analysis', [AiController::class, 'atsAnalysis'])->middleware('throttle:10,1,ai-ats-analysis:');
    Route::post('ai/cover-letter', [AiController::class, 'coverLetter'])->middleware('throttle:10,1,ai-cover-letter:');
    Route::post('ats/document', [AtsDocumentController::class, 'analyze'])->middleware('throttle:20,1,ats-document:');
    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('site-settings', [SiteSettingsController::class, 'show'])->middleware('deprecated:/api/v1/site-settings');
        Route::put('site-settings', [SiteSettingsController::class, 'save'])->middleware('deprecated:/api/v1/site-settings');
        Route::get('system', [SiteSettingsController::class, 'system']);
        Route::get('contact-messages', [SupportController::class, 'index']);
        Route::patch('contact-messages/{message}', [SupportController::class, 'update']);
        Route::get('summary', [AdminController::class, 'summary']);
        Route::get('cv-templates', [CvTemplateController::class, 'index'])->middleware('deprecated:/api/v1/cv-templates');
        Route::post('cv-templates', [CvTemplateController::class, 'store'])->middleware('deprecated:/api/v1/cv-templates');
        Route::patch('cv-templates/{template}', [CvTemplateController::class, 'update']);
        Route::delete('cv-templates/{template}', [CvTemplateController::class, 'destroy']);
        Route::get('smtp', [MailSettingsController::class, 'show']);
        Route::post('smtp/microsoft/connect', [MailSettingsController::class, 'connectMicrosoft'])->middleware('throttle:5,1,smtp-connect:');
        Route::put('smtp', [MailSettingsController::class, 'save']);
        Route::post('smtp/check', [MailSettingsController::class, 'check'])->middleware('throttle:3,1,smtp-check:');
        Route::post('smtp/test', [MailSettingsController::class, 'test'])->middleware('throttle:3,1,smtp-test:');
        Route::get('users', [AdminController::class, 'users']);
        Route::get('users/{user}', [AdminController::class, 'detail']);
        Route::post('users/{user}/warning', [AdminController::class, 'warning'])->middleware('throttle:10,1,warning:');
        Route::get('applications', [AdminController::class, 'applications']);
        Route::get('uploads/{cv}', [AdminController::class, 'download']);
        Route::patch('users/{user}', [AdminController::class, 'suspend']);
        Route::get('logs', [AdminController::class, 'logs']);
        Route::get('analytics', [AnalyticsController::class, 'report']);
        Route::get('integrations', [IntegrationController::class, 'index']);
        Route::post('integrations', [IntegrationController::class, 'store']);
        Route::post('integrations/reorder', [IntegrationController::class, 'reorder']);
        Route::patch('integrations/{integration}', [IntegrationController::class, 'update']);
        Route::post('integrations/{integration}/test', [IntegrationController::class, 'test'])->middleware('throttle:10,1,integrations--integration--test:');
        Route::delete('integrations/{integration}', [IntegrationController::class, 'destroy']);
        Route::post('cache/clear', [CacheController::class, 'clear']);
    });
});
