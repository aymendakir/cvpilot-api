<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Requests\Admin\SaveSmtpSettingsRequest;
use App\Http\Resources\MailSettingResource;
use App\Models\MailSetting;
use App\Services\BrevoSmtp;
use App\Services\MailConfigurationException;
use App\Services\MicrosoftSmtpOAuth;
use App\Services\PlatformMail;
use App\Support\Redactor;
use App\Support\UpstreamFailure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class MailSettingsController
{
    /**
     * Stores the redacted reason as last_error, logs the full diagnostic (with the request id) and
     * returns the 502/503 to throw. The response body stays generic.
     */
    private function failed(PlatformMail $mail, \Throwable $error, string $operation): ApiException
    {
        $diagnostic = $mail->failureResponse($error, $operation); // logs the SMTP diagnostic with a reference
        $stored = MailSetting::first();
        $secrets = $stored ? [$stored->password, $stored->oauth_client_secret, $stored->oauth_refresh_token, $stored->oauth_access_token] : [];
        $reason = mb_substr(Redactor::scrub($mail->failureMessage($error), $secrets), 0, 500);
        $stored?->update(['last_error' => $reason]);
        Log::warning('SMTP operation failed', [
            'operation' => $operation, 'reason' => $reason, 'reference' => $diagnostic['diagnostic']['reference'] ?? null,
            'request_id' => Context::get('request_id'),
        ]);

        return UpstreamFailure::exception($error, $reason);
    }

    private function clearLastError(): void
    {
        MailSetting::whereNotNull('last_error')->update(['last_error' => null]);
    }

    private function settings(): MailSetting
    {
        return MailSetting::first() ?? new MailSetting([
            'id' => 1, 'host' => config('mail.mailers.smtp.host') ?? '',
            'port' => (int) config('mail.mailers.smtp.port', 587),
            'username' => config('mail.mailers.smtp.username') ?? '',
            'password' => config('mail.mailers.smtp.password'),
            'encryption' => config('mail.mailers.smtp.scheme') === 'smtps' ? 'ssl' : 'tls',
            'from_address' => config('mail.from.address') ?? '', 'from_name' => config('mail.from.name', 'CVPilot AI'),
            'auth_mode' => 'password', 'oauth_tenant' => 'common',
        ]);
    }

    public function show(MicrosoftSmtpOAuth $oauth)
    {
        $settings = $this->settings();
        try {
            $redirect = $oauth->redirectUri();
        } catch (MailConfigurationException) {
            $redirect = '';
        }

        return MailSettingResource::make($settings, $redirect);
    }

    public function save(SaveSmtpSettingsRequest $request)
    {
        $data = $request->validated();
        $settings = $this->settings();
        $data['auth_mode'] = $data['auth_mode'] ?? $settings->auth_mode ?? 'password';
        $data['oauth_tenant'] = $data['oauth_tenant'] ?? $settings->oauth_tenant ?? 'common';
        if (($data['port'] == 465 && $data['encryption'] !== 'ssl') || ($data['port'] == 587 && $data['encryption'] !== 'tls')) {
            throw ValidationException::withMessages(['encryption' => 'Use SSL/TLS for port 465 or STARTTLS for port 587.']);
        }
        if ($data['host'] === 'smtp-mail.outlook.com' && $data['auth_mode'] !== 'microsoft') {
            throw ValidationException::withMessages(['auth_mode' => 'Outlook.com requires Microsoft sign-in (OAuth2). Select the Outlook.com provider and connect your account.']);
        }
        if ($data['auth_mode'] === 'microsoft') {
            if (! in_array($data['host'], MicrosoftSmtpOAuth::HOSTS, true) || $data['port'] != 587 || $data['encryption'] !== 'tls') {
                throw ValidationException::withMessages(['host' => 'Microsoft sign-in requires smtp-mail.outlook.com or smtp.office365.com, port 587, STARTTLS.']);
            }
            validator($data, ['username' => 'required|email', 'oauth_client_id' => 'required|uuid'])->validate();
        }
        if ($data['host'] === 'smtp.gmail.com') {
            validator($data, ['username' => 'required|email'])->validate();
            if (isset($data['password'])) {
                $data['password'] = preg_replace('/\s+/', '', $data['password']);
            }
        }
        if ($data['host'] === BrevoSmtp::HOST && isset($data['password'])) {
            $data['password'] = trim($data['password']);
        }
        $identityChanged = $settings->host !== $data['host'] ||
            (string) $settings->username !== (string) ($data['username'] ?? '') || $settings->auth_mode !== $data['auth_mode'];
        $applicationChanged = (string) $settings->oauth_client_id !== (string) ($data['oauth_client_id'] ?? $settings->oauth_client_id) ||
            $settings->oauth_tenant !== $data['oauth_tenant'];
        $hasNewPassword = isset($data['password']) && $data['password'] !== '';
        $hasNewSecret = isset($data['oauth_client_secret']) && $data['oauth_client_secret'] !== '';
        if (! $hasNewPassword) {
            unset($data['password']);
        }
        if (! $hasNewSecret) {
            unset($data['oauth_client_secret']);
        }
        if ($identityChanged) {
            $settings->password = null;
        }
        if ($applicationChanged) {
            $settings->oauth_client_secret = null;
        }
        if ($identityChanged || $applicationChanged || $hasNewSecret) {
            $settings->clearOAuthTokens();
        }
        $settings->fill($data);
        if ($settings->auth_mode === 'microsoft') {
            $settings->password = null;
            if (empty($settings->getAttributes()['oauth_client_secret'])) {
                throw ValidationException::withMessages(['oauth_client_secret' => 'Enter the Microsoft application client secret value.']);
            }
        } elseif ($settings->host === BrevoSmtp::HOST) {
            $errors = BrevoSmtp::validationErrors($settings->only(['host', 'port', 'encryption', 'username', 'password', 'from_address']));
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
        } elseif ($settings->username && empty($settings->getAttributes()['password'])) {
            throw ValidationException::withMessages(['password' => 'Enter the SMTP password for this account. Gmail requires an App Password.']);
        }
        $settings->save();
        AuthController::audit($request, 'smtp_updated', $request->user()->id);

        return ['message' => 'SMTP settings saved.'];
    }

    public function check(PlatformMail $mail)
    {
        try {
            $mail->checkConnection();
        } catch (\Throwable $error) {
            throw $this->failed($mail, $error, 'connection');
        }
        $this->clearLastError();

        return ['message' => 'SMTP connection, TLS and configured authentication succeeded. No email was sent. Send a test email next to check sender acceptance and delivery.'];
    }

    public function test(Request $request, PlatformMail $mail)
    {
        try {
            $mail->send($request->user()->email, 'CVPilot · SMTP test', 'emails.notice', [
                'name' => $request->user()->name, 'heading' => 'Your email service is ready',
                'noticeMessage' => 'This test confirms CVPilot can send email with your saved SMTP settings.',
            ]);
        } catch (\Throwable $error) {
            throw $this->failed($mail, $error, 'send');
        }
        $this->clearLastError();

        return ['message' => 'The SMTP server accepted the test email for '.$request->user()->email.'. Check the inbox and spam folder.'];
    }

    public function connectMicrosoft(Request $request, MicrosoftSmtpOAuth $oauth, PlatformMail $mail)
    {
        $settings = MailSetting::first();
        if (! $settings) {
            return response()->json(['message' => 'Save your Microsoft SMTP settings first.'], 422);
        }
        $state = bin2hex(random_bytes(32));
        $verifier = bin2hex(random_bytes(32));
        try {
            $url = $oauth->authorizationUrl($settings, $state, $verifier);
            $request->session()->put('smtp_microsoft_oauth', [
                'state' => $state, 'verifier' => $verifier, 'expires' => time() + 600,
                'fingerprint' => $oauth->fingerprint($settings), 'user_id' => $request->user()->id,
            ]);

            return ['url' => $url];
        } catch (\Throwable $error) {
            throw $this->failed($mail, $error, 'connect');
        }
    }

    public function microsoftCallback(Request $request, MicrosoftSmtpOAuth $oauth, PlatformMail $mail)
    {
        $pending = $request->session()->pull('smtp_microsoft_oauth');
        $settings = MailSetting::first();
        $success = false;
        $message = 'Microsoft connection expired or is invalid. Return to SMTP settings and connect again.';
        try {
            if ($pending && $settings && is_string($request->query('state')) &&
                hash_equals($pending['state'], $request->query('state')) && $pending['expires'] > time() &&
                $pending['user_id'] === $request->user()->id && hash_equals($pending['fingerprint'], $oauth->fingerprint($settings))) {
                if ($request->query('error')) {
                    $message = 'Microsoft connection was not approved. Retry and allow the requested mail permission.';
                } elseif (is_string($request->query('code')) && $request->query('code') !== '') {
                    $oauth->connect($settings, $request->query('code'), $pending['verifier']);
                    $success = true;
                    $message = 'Microsoft account connected. Return to SMTP settings and send a test email.';
                    AuthController::audit($request, 'smtp_microsoft_connected', $request->user()->id);
                }
            }
        } catch (\Throwable $error) {
            $message = $mail->failureMessage($error);
        }
        $frontend = rtrim((string) config('mail.frontend_url'), '/');
        $returnUrl = filter_var($frontend, FILTER_VALIDATE_URL) && in_array(parse_url($frontend, PHP_URL_SCHEME), ['https', 'http'], true)
            ? $frontend.'/dashboard?section=smtp' : null;

        return response()->view('smtp-oauth-result', compact('success', 'message', 'returnUrl'), $success ? 200 : 422)
            ->header('Referrer-Policy', 'no-referrer');
    }
}
