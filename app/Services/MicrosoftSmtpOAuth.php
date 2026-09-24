<?php
namespace App\Services;

use App\Models\MailSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MicrosoftSmtpOAuth {
    public const SCOPE = 'https://outlook.office.com/SMTP.Send offline_access';
    public const HOSTS = ['smtp-mail.outlook.com', 'smtp.office365.com'];

    public function redirectUri(): string {
        $url = rtrim((string) config('app.url'), '/');
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)) {
            throw new MailConfigurationException('Set APP_URL to the public backend URL before connecting Microsoft.');
        }
        return $url.'/api/admin/smtp/microsoft/callback';
    }

    // Bind a pending authorization to the exact mailbox and application settings.
    public function fingerprint(MailSetting $settings): string {
        return hash('sha256', json_encode([
            $settings->host, $settings->username, $settings->auth_mode,
            $settings->oauth_tenant, $settings->oauth_client_id, $settings->oauth_client_secret,
        ], JSON_THROW_ON_ERROR));
    }

    public function authorizationUrl(MailSetting $settings, string $state, string $verifier): string {
        $this->assertSettings($settings);
        return $this->endpoint($settings, 'authorize').'?'.http_build_query([
            'client_id'=>$settings->oauth_client_id, 'response_type'=>'code', 'response_mode'=>'query',
            'redirect_uri'=>$this->redirectUri(), 'scope'=>self::SCOPE, 'state'=>$state,
            'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method'=>'S256', 'prompt'=>'select_account', 'login_hint'=>$settings->username,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function connect(MailSetting $settings, string $code, string $verifier): void {
        $this->assertSettings($settings);
        $tokens = $this->requestTokens($settings, [
            'grant_type'=>'authorization_code', 'code'=>$code, 'code_verifier'=>$verifier,
            'redirect_uri'=>$this->redirectUri(),
        ]);
        if (empty($tokens['refresh_token'])) {
            throw new MailConfigurationException('Microsoft did not grant offline access. Reconnect and approve the mail permission.');
        }
        $this->storeTokens($settings, $tokens);
    }

    public function accessToken(MailSetting $settings): string {
        $this->assertSettings($settings);
        return Cache::lock('smtp-microsoft-token:'.$settings->id, 30)->block(20, function () use ($settings) {
            $fingerprint = $this->fingerprint($settings);
            $settings->refresh();
            if (!hash_equals($fingerprint, $this->fingerprint($settings))) {
                throw new MailConfigurationException('SMTP settings changed. Retry using the saved settings.');
            }
            if (!$settings->oauth_refresh_token) {
                throw new MailConfigurationException('Connect your Microsoft account in SMTP settings before sending email.');
            }
            if ($settings->oauth_access_token && $settings->oauth_expires_at?->gt(now()->addMinute())) {
                return $settings->oauth_access_token;
            }
            $tokens = $this->requestTokens($settings, [
                'grant_type'=>'refresh_token', 'refresh_token'=>$settings->oauth_refresh_token,
            ]);
            $this->storeTokens($settings, $tokens);
            return $settings->oauth_access_token;
        });
    }

    private function assertSettings(MailSetting $settings): void {
        if ($settings->auth_mode !== 'microsoft' || !in_array($settings->host, self::HOSTS, true)) {
            throw new MailConfigurationException('Microsoft authentication requires an Outlook or Microsoft 365 SMTP server.');
        }
        if (!$settings->oauth_client_id || !$settings->oauth_client_secret || !$settings->username) {
            throw new MailConfigurationException('Save the mailbox email, Microsoft application ID and client secret first.');
        }
    }

    private function endpoint(MailSetting $settings, string $action): string {
        $tenant = $settings->oauth_tenant ?: 'common';
        if (!preg_match('/^[a-zA-Z0-9.-]+$/', $tenant)) {
            throw new MailConfigurationException('The Microsoft tenant is invalid.');
        }
        return 'https://login.microsoftonline.com/'.$tenant.'/oauth2/v2.0/'.$action;
    }

    private function requestTokens(MailSetting $settings, array $grant): array {
        try {
            $response = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(15)
                ->withOptions(['allow_redirects'=>false])->post($this->endpoint($settings, 'token'), $grant + [
                    'client_id'=>$settings->oauth_client_id, 'client_secret'=>$settings->oauth_client_secret,
                    'scope'=>self::SCOPE,
                ]);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            throw new MailConfigurationException('Cannot reach Microsoft sign-in. Check the backend internet connection and retry.');
        }
        // Never expose token endpoint bodies, authorization codes, or credentials.
        if (!$response->successful()) {
            $message = match ($response->json('error')) {
                'invalid_client'=>'Microsoft rejected the application credentials. Check the client ID and secret value (not the secret ID).',
                'invalid_grant', 'interaction_required', 'consent_required'=>'Microsoft authorization expired or was revoked. Reconnect your Microsoft account.',
                'invalid_scope'=>'The Microsoft application needs the delegated SMTP.Send permission and offline access.',
                default=>'Microsoft connection failed. Check the application settings and tenant permissions, then reconnect.',
            };
            throw new MailConfigurationException($message);
        }
        $tokens = $response->json();
        if (!is_array($tokens) || !is_string($tokens['access_token'] ?? null) || empty($tokens['access_token']) ||
            !is_numeric($tokens['expires_in'] ?? null) || (int) $tokens['expires_in'] <= 0 ||
            (isset($tokens['refresh_token']) && !is_string($tokens['refresh_token']))) {
            throw new MailConfigurationException('Microsoft returned an incomplete authorization. Reconnect your account.');
        }
        return $tokens;
    }

    private function storeTokens(MailSetting $settings, array $tokens): void {
        $settings->oauth_access_token = $tokens['access_token'];
        if (!empty($tokens['refresh_token'])) $settings->oauth_refresh_token = $tokens['refresh_token'];
        $settings->oauth_expires_at = now()->addSeconds((int) $tokens['expires_in']);
        $settings->save();
    }
}
