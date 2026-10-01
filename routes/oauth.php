<?php

/*
 * Microsoft SMTP OAuth callback. The URL is registered with Microsoft (see
 * MicrosoftSmtpOAuth::redirectUri()), so it is permanent: never renamed,
 * never deprecated, not part of /api/v1.
 */

use App\Http\Controllers\Api\V1\Admin\MailSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:api', 'member', 'admin'])
    ->get('admin/smtp/microsoft/callback', [MailSettingsController::class, 'microsoftCallback'])
    ->name('smtp.microsoft.callback');
