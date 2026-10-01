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

Route::get('csrf', CsrfTokenController::class)->middleware('deprecated:/api/v1/csrf');
Route::get('site-settings', [SiteSettingsController::class, 'show'])->middleware('deprecated:/api/v1/site-settings');
Route::post('contact', [SupportController::class, 'store'])->middleware('throttle:3,10,contact:')->middleware('deprecated:/api/v1/contact-messages');
Route::post('analytics/events', [AnalyticsController::class, 'store'])->middleware('throttle:120,1,analytics-events:')->middleware('deprecated:/api/v1/analytics/events');
Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register')->middleware('deprecated:/api/v1/auth/register');
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->middleware('deprecated:/api/v1/auth/login');
Route::post('otp/request', [AuthController::class, 'code'])->middleware('throttle:otp-send')->middleware('deprecated:/api/v1/auth/otp/request');
Route::post('otp/verify', [AuthController::class, 'verify'])->middleware('throttle:otp-verify')->middleware('deprecated:/api/v1/auth/otp/verify');
Route::get('cv-templates', [CvTemplateController::class, 'published'])->middleware('throttle:60,1,cv-templates:')->middleware('deprecated:/api/v1/cv-templates');
// Added in S5 for the deployed frontend, which already calls these paths; removed with the other aliases in S7.
Route::withoutMiddleware('web')->middleware('throttle:60,1,blog:')->group(function () {
    Route::get('blog', [BlogController::class, 'index'])->middleware('deprecated:/api/v1/blog');
    Route::get('blog/{slug}', [BlogController::class, 'show'])->where('slug', BlogPost::SLUG_PATTERN)->middleware('deprecated:/api/v1/blog/{slug}');
});
Route::middleware(['throttle:api', 'auth.session'])->group(function () {
    Route::get('me/export', [AccountDataController::class, 'export'])->middleware('throttle:3,1,account-export:')->middleware('deprecated:/api/v1/me/export');
    Route::delete('me', [AccountDataController::class, 'destroy'])->middleware('throttle:3,10,account-delete:')->middleware('deprecated:/api/v1/me');
    Route::get('me', CurrentUserController::class)->middleware('deprecated:/api/v1/me');
    Route::patch('me', [AuthController::class, 'profile'])->middleware('deprecated:/api/v1/me');
    Route::post('password', [AuthController::class, 'password'])->middleware('deprecated:/api/v1/me/password');
    Route::post('logout', [AuthController::class, 'logoutLegacy'])->middleware('deprecated:/api/v1/auth/logout');
    Route::post('jobs/search', [JobsController::class, 'search'])->middleware('throttle:20,1,jobs-search:')->middleware('deprecated:/api/v1/jobs/search');
    Route::get('jobs/links', [JobsController::class, 'links'])->middleware('deprecated:/api/v1/jobs/links');
    Route::get('jobs/saved-searches', [JobsController::class, 'saved'])->middleware('deprecated:/api/v1/jobs/saved-searches');
    Route::post('jobs/saved-searches', [JobsController::class, 'save'])->middleware('deprecated:/api/v1/jobs/saved-searches');
    Route::delete('jobs/saved-searches/{search}', [JobsController::class, 'destroySaved'])->middleware('deprecated:/api/v1/jobs/saved-searches/{search}');
    Route::get('cv', [CvController::class, 'index'])->middleware('deprecated:/api/v1/cv-documents');
    Route::post('cv', [CvController::class, 'store'])->middleware('throttle:10,1,cv:')->middleware('deprecated:/api/v1/cv-documents');
    Route::post('cv/extract', [CvController::class, 'extract'])->middleware('throttle:20,1,cv-extract:')->middleware('deprecated:/api/v1/cv-documents/extract');
    Route::post('cv/{cv}/analyze', [CvController::class, 'analyze'])->middleware('deprecated');
    Route::delete('cv/{cv}', [CvController::class, 'destroy'])->middleware('deprecated:/api/v1/cv-documents/{cv}');
    Route::get('applications', [ApplicationController::class, 'index'])->middleware('deprecated:/api/v1/applications');
    Route::post('applications', [ApplicationController::class, 'store'])->middleware('deprecated:/api/v1/applications');
    Route::match(['PUT', 'PATCH'], 'applications/{application}', [ApplicationController::class, 'update'])->middleware('deprecated:/api/v1/applications/{application}');
    Route::delete('applications/{application}', [ApplicationController::class, 'destroy'])->middleware('deprecated:/api/v1/applications/{application}');
    Route::get('career/library', LibraryController::class)->middleware('deprecated:/api/v1/library');
    Route::get('career/interviews/{session}', [InterviewController::class, 'show'])->middleware('deprecated:/api/v1/interviews/{session}');
    Route::get('career/workspaces/{workspace}', [JobWorkspaceController::class, 'show'])->middleware('deprecated:/api/v1/job-workspaces/{workspace}');
    Route::get('career/cv-versions/{version}', [CvVersionController::class, 'show'])->middleware('deprecated:/api/v1/cv-versions/{version}');
    Route::patch('career/cv-versions/{version}', [CvVersionController::class, 'update'])->middleware('deprecated:/api/v1/cv-versions/{version}');
    Route::get('career/dashboard', [InsightsController::class, 'dashboard'])->middleware('deprecated:/api/v1/me/dashboard');
    Route::get('career/workspaces', [JobWorkspaceController::class, 'index'])->middleware('deprecated:/api/v1/job-workspaces');
    Route::post('career/workspaces', [JobWorkspaceController::class, 'store'])->middleware('deprecated:/api/v1/job-workspaces');
    Route::get('career/cv-versions', [CvVersionController::class, 'index'])->middleware('deprecated:/api/v1/cv-versions');
    Route::post('career/cv-versions', [CvVersionController::class, 'store'])->middleware('deprecated:/api/v1/cv-versions');
    Route::delete('career/cv-versions/{version}', [CvVersionController::class, 'destroy'])->middleware('deprecated:/api/v1/cv-versions/{version}');
    Route::get('career/analytics', [InsightsController::class, 'analytics'])->middleware('deprecated:/api/v1/me/analytics');
    Route::post('career/recruiter-view', [CareerAiController::class, 'recruiterView'])->middleware('throttle:10,1,career-recruiter-view:')->middleware('deprecated:/api/v1/ai/recruiter-view');
    Route::post('career/tailor-cv', [CareerAiController::class, 'tailorCv'])->middleware('throttle:8,1,career-tailor-cv:')->middleware('deprecated:/api/v1/ai/tailor-cv');
    Route::post('career/application-pack', [CareerAiController::class, 'applicationPack'])->middleware('throttle:6,1,career-application-pack:')->middleware('deprecated:/api/v1/ai/application-pack');
    Route::post('career/skill-gap', [CareerAiController::class, 'skillGap'])->middleware('throttle:10,1,career-skill-gap:')->middleware('deprecated:/api/v1/ai/skill-gap');
    Route::post('career/portfolio', [CareerAiController::class, 'portfolio'])->middleware('throttle:10,1,career-portfolio:')->middleware('deprecated:/api/v1/ai/portfolio-review');
    Route::post('career/follow-up', [CareerAiController::class, 'followUp'])->middleware('throttle:10,1,career-follow-up:')->middleware('deprecated:/api/v1/ai/follow-up');
    Route::post('career/diagnostic', [CareerAiController::class, 'diagnostic'])->middleware('throttle:5,1,career-diagnostic:')->middleware('deprecated:/api/v1/ai/career-diagnostic');
    Route::delete('career/reports/{report}', [ReportController::class, 'destroy'])->middleware('deprecated:/api/v1/reports/{report}');
    Route::delete('career/interviews/{session}', [InterviewController::class, 'destroy'])->middleware('deprecated:/api/v1/interviews/{session}');
    Route::post('career/interviews', [InterviewController::class, 'store'])->middleware('throttle:10,1,career-interviews:')->middleware('deprecated:/api/v1/interviews');
    Route::post('career/interviews/{session}/reply', [InterviewController::class, 'reply'])->middleware('throttle:20,1,career-interviews--session--reply:')->middleware('deprecated:/api/v1/interviews/{session}/reply');
    Route::post('career/interviews/{session}/finish', [InterviewController::class, 'finish'])->middleware('throttle:5,1,career-interviews--session--finish:')->middleware('deprecated:/api/v1/interviews/{session}/finish');
    Route::post('ai/chat', [AiController::class, 'chat'])->middleware('throttle:20,1,ai-chat:')->middleware('deprecated:/api/v1/ai/chat');
    Route::post('ai/improve-cv', [AiController::class, 'improveCv'])->middleware('throttle:10,1,ai-improve-cv:')->middleware('deprecated');
    Route::post('ai/ats-analysis', [AiController::class, 'atsAnalysis'])->middleware('throttle:10,1,ai-ats-analysis:')->middleware('deprecated:/api/v1/ai/ats-analysis');
    Route::post('ai/cover-letter', [AiController::class, 'coverLetter'])->middleware('throttle:10,1,ai-cover-letter:')->middleware('deprecated:/api/v1/ai/cover-letter');
    Route::post('ats/document', [AtsDocumentController::class, 'analyze'])->middleware('throttle:20,1,ats-document:')->middleware('deprecated:/api/v1/ats/document');
    Route::prefix('admin')->middleware(['admin', 'throttle:admin', 'admin.audit'])->group(function () {
        Route::get('site-settings', [SiteSettingsController::class, 'show'])->middleware('deprecated:/api/v1/admin/site-settings');
        Route::put('site-settings', [SiteSettingsController::class, 'save'])->middleware('deprecated:/api/v1/admin/site-settings');
        Route::get('system', [SiteSettingsController::class, 'system'])->middleware('deprecated:/api/v1/admin/system');
        Route::get('contact-messages', [SupportController::class, 'index'])->middleware('deprecated:/api/v1/admin/contact-messages');
        Route::patch('contact-messages/{message}', [SupportController::class, 'update'])->middleware('deprecated:/api/v1/admin/contact-messages/{message}');
        Route::get('summary', [AdminController::class, 'summary'])->middleware('deprecated:/api/v1/admin/summary');
        Route::get('cv-templates', [CvTemplateController::class, 'index'])->middleware('deprecated:/api/v1/admin/cv-templates');
        Route::post('cv-templates', [CvTemplateController::class, 'store'])->middleware('deprecated:/api/v1/admin/cv-templates');
        Route::patch('cv-templates/{template}', [CvTemplateController::class, 'update'])->middleware('deprecated:/api/v1/admin/cv-templates/{template}');
        Route::delete('cv-templates/{template}', [CvTemplateController::class, 'destroy'])->middleware('deprecated:/api/v1/admin/cv-templates/{template}');
        Route::get('smtp', [MailSettingsController::class, 'show'])->middleware('deprecated:/api/v1/admin/smtp');
        Route::post('smtp/microsoft/connect', [MailSettingsController::class, 'connectMicrosoft'])->middleware('throttle:5,1,smtp-connect:')->middleware('deprecated:/api/v1/admin/smtp/microsoft/connect');
        Route::put('smtp', [MailSettingsController::class, 'save'])->middleware('deprecated:/api/v1/admin/smtp');
        Route::post('smtp/check', [MailSettingsController::class, 'check'])->middleware('throttle:3,1,smtp-check:')->middleware('deprecated:/api/v1/admin/smtp/check');
        Route::post('smtp/test', [MailSettingsController::class, 'test'])->middleware('throttle:3,1,smtp-test:')->middleware('deprecated:/api/v1/admin/smtp/test');
        Route::get('users', [AdminController::class, 'users'])->middleware('deprecated:/api/v1/admin/users');
        Route::get('users/{user}', [AdminController::class, 'detail'])->middleware('deprecated:/api/v1/admin/users/{user}');
        Route::post('users/{user}/warning', [AdminController::class, 'warning'])->middleware('throttle:10,1,warning:')->middleware('deprecated:/api/v1/admin/users/{user}/warnings');
        Route::get('applications', [AdminController::class, 'applications'])->middleware('deprecated:/api/v1/admin/applications');
        Route::patch('users/{user}', [AdminController::class, 'suspend'])->middleware('deprecated:/api/v1/admin/users/{user}');
        Route::get('logs', [AdminController::class, 'logs'])->middleware('deprecated:/api/v1/admin/audit-events');
        Route::get('analytics', [AnalyticsController::class, 'report'])->middleware('deprecated:/api/v1/admin/analytics');
        Route::get('integrations', [IntegrationController::class, 'index'])->middleware('deprecated:/api/v1/admin/integrations');
        Route::post('integrations', [IntegrationController::class, 'store'])->middleware('deprecated:/api/v1/admin/integrations');
        Route::post('integrations/reorder', [IntegrationController::class, 'reorder'])->middleware('deprecated:/api/v1/admin/integrations/reorder');
        Route::patch('integrations/{integration}', [IntegrationController::class, 'update'])->middleware('deprecated:/api/v1/admin/integrations/{integration}');
        Route::post('integrations/{integration}/test', [IntegrationController::class, 'test'])->middleware('throttle:10,1,integrations--integration--test:')->middleware('deprecated:/api/v1/admin/integrations/{integration}/test');
        Route::delete('integrations/{integration}', [IntegrationController::class, 'destroy'])->middleware('deprecated:/api/v1/admin/integrations/{integration}');
        Route::post('cache/clear', [CacheController::class, 'clear'])->middleware('deprecated:/api/v1/admin/cache');
    });
});
