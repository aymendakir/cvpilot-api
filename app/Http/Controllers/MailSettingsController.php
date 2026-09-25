<?php
namespace App\Http\Controllers;

use App\Models\MailSetting;
use App\Services\BrevoSmtp;
use App\Services\MicrosoftSmtpOAuth;
use App\Services\PlatformMail;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MailSettingsController {
    private function settings(): MailSetting {
        return MailSetting::first() ?? new MailSetting([
            'id'=>1, 'host'=>config('mail.mailers.smtp.host') ?? '',
            'port'=>(int) config('mail.mailers.smtp.port', 587),
            'username'=>config('mail.mailers.smtp.username') ?? '',
            'password'=>config('mail.mailers.smtp.password'),
            'encryption'=>config('mail.mailers.smtp.scheme') === 'smtps' ? 'ssl' : 'tls',
            'from_address'=>config('mail.from.address') ?? '', 'from_name'=>config('mail.from.name', 'CVPilot AI'),
            'auth_mode'=>'password', 'oauth_tenant'=>'common',
        ]);
    }

    public function show(MicrosoftSmtpOAuth $oauth) {
        $settings = $this->settings();
        try { $redirect = $oauth->redirectUri(); } catch (\App\Services\MailConfigurationException) { $redirect = ''; }
        return $settings->only(['host', 'port', 'username', 'encryption', 'from_address', 'from_name', 'auth_mode', 'oauth_tenant', 'oauth_client_id']) + [
            'has_password'=>!empty($settings->getRawOriginal('password')) || !empty($settings->getAttributes()['password']),
            'has_oauth_client_secret'=>!empty($settings->getAttributes()['oauth_client_secret']),
            'oauth_connected'=>!empty($settings->getAttributes()['oauth_refresh_token']),
            'oauth_redirect_uri'=>$redirect,
        ];
    }

    public function save(Request $request) {
        foreach (['host', 'username', 'from_address', 'oauth_client_id', 'oauth_tenant'] as $field) {
            if (is_string($request->input($field))) $request->merge([$field=>trim($request->input($field))]);
        }
        if (is_string($request->input('host'))) $request->merge(['host'=>strtolower($request->input('host'))]);
        $data = $request->validate([
            'host'=>'required|string|max:253|regex:/^[a-zA-Z0-9.-]+$/', 'port'=>'required|integer|between:1,65535',
            'encryption'=>'required|in:tls,ssl', 'username'=>'nullable|string|max:254', 'password'=>'nullable|string|max:2000',
            'from_address'=>'required|email|max:254', 'from_name'=>'required|string|max:120',
            'auth_mode'=>'sometimes|required|in:password,microsoft',
            'oauth_tenant'=>'nullable|string|max:253|regex:/^[a-zA-Z0-9.-]+$/',
            'oauth_client_id'=>'nullable|uuid', 'oauth_client_secret'=>'nullable|string|max:2000',
        ]);
        $settings = $this->settings();
        $data['auth_mode'] = $data['auth_mode'] ?? $settings->auth_mode ?? 'password';
        $data['oauth_tenant'] = $data['oauth_tenant'] ?? $settings->oauth_tenant ?? 'common';
        if (($data['port'] == 465 && $data['encryption'] !== 'ssl') || ($data['port'] == 587 && $data['encryption'] !== 'tls')) {
            throw ValidationException::withMessages(['encryption'=>'Use SSL/TLS for port 465 or STARTTLS for port 587.']);
        }
        if ($data['host'] === 'smtp-mail.outlook.com' && $data['auth_mode'] !== 'microsoft') {
            throw ValidationException::withMessages(['auth_mode'=>'Outlook.com requires Microsoft sign-in (OAuth2). Select the Outlook.com provider and connect your account.']);
        }
        if ($data['auth_mode'] === 'microsoft') {
            if (!in_array($data['host'], MicrosoftSmtpOAuth::HOSTS, true) || $data['port'] != 587 || $data['encryption'] !== 'tls') {
                throw ValidationException::withMessages(['host'=>'Microsoft sign-in requires smtp-mail.outlook.com or smtp.office365.com, port 587, STARTTLS.']);
            }
            validator($data, ['username'=>'required|email', 'oauth_client_id'=>'required|uuid'])->validate();
        }
        if ($data['host'] === 'smtp.gmail.com') {
            validator($data, ['username'=>'required|email'])->validate();
            if (isset($data['password'])) $data['password'] = preg_replace('/\s+/', '', $data['password']);
        }
        if ($data['host'] === BrevoSmtp::HOST && isset($data['password'])) $data['password'] = trim($data['password']);
        $identityChanged = $settings->host !== $data['host'] ||
            (string) $settings->username !== (string) ($data['username'] ?? '') || $settings->auth_mode !== $data['auth_mode'];
        $applicationChanged = (string) $settings->oauth_client_id !== (string) ($data['oauth_client_id'] ?? $settings->oauth_client_id) ||
            $settings->oauth_tenant !== $data['oauth_tenant'];
        $hasNewPassword = isset($data['password']) && $data['password'] !== '';
        $hasNewSecret = isset($data['oauth_client_secret']) && $data['oauth_client_secret'] !== '';
        if (!$hasNewPassword) unset($data['password']);
        if (!$hasNewSecret) unset($data['oauth_client_secret']);
        if ($identityChanged) $settings->password = null;
        if ($applicationChanged) $settings->oauth_client_secret = null;
        if ($identityChanged || $applicationChanged || $hasNewSecret) $settings->clearOAuthTokens();
        $settings->fill($data);
        if ($settings->auth_mode === 'microsoft') {
            $settings->password = null;
            if (empty($settings->getAttributes()['oauth_client_secret'])) {
                throw ValidationException::withMessages(['oauth_client_secret'=>'Enter the Microsoft application client secret value.']);
            }
        } elseif ($settings->host === BrevoSmtp::HOST) {
            $errors = BrevoSmtp::validationErrors($settings->only(['host', 'port', 'encryption', 'username', 'password', 'from_address']));
            if ($errors) throw ValidationException::withMessages($errors);
        } elseif ($settings->username && empty($settings->getAttributes()['password'])) {
            throw ValidationException::withMessages(['password'=>'Enter the SMTP password for this account. Gmail requires an App Password.']);
        }
        $settings->save();
        AuthController::audit($request, 'smtp_updated', $request->user()->id);
        return ['message'=>'SMTP settings saved.'];
    }

    public function test(Request $request, PlatformMail $mail) {
        try {
            $mail->send($request->user()->email, 'CVPilot · SMTP test', 'emails.notice', [
                'name'=>$request->user()->name, 'heading'=>'Your email service is ready',
                'noticeMessage'=>'This test confirms CVPilot can send email with your saved SMTP settings.',
            ]);
        } catch (\Throwable $error) {
            return response()->json(['message'=>$mail->failureMessage($error)], 422);
        }
        return ['message'=>'The SMTP server accepted the test email for '.$request->user()->email.'. Check the inbox and spam folder.'];
    }

    public function connectMicrosoft(Request $request, MicrosoftSmtpOAuth $oauth, PlatformMail $mail) {
        $settings = MailSetting::first();
        if (!$settings) return response()->json(['message'=>'Save your Microsoft SMTP settings first.'], 422);
        $state = bin2hex(random_bytes(32));
        $verifier = bin2hex(random_bytes(32));
        try {
            $url = $oauth->authorizationUrl($settings, $state, $verifier);
            $request->session()->put('smtp_microsoft_oauth', [
                'state'=>$state, 'verifier'=>$verifier, 'expires'=>time() + 600,
                'fingerprint'=>$oauth->fingerprint($settings), 'user_id'=>$request->user()->id,
            ]);
            return ['url'=>$url];
        } catch (\Throwable $error) {
            return response()->json(['message'=>$mail->failureMessage($error)], 422);
        }
    }

    public function microsoftCallback(Request $request, MicrosoftSmtpOAuth $oauth, PlatformMail $mail) {
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
