<?php
namespace App\Services;

use App\Models\MailSetting;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

class PlatformMail {
    public function send(string $to, string $subject, string $view, array $data): void {
        $settings = MailSetting::first();
        if ($settings) {
            $password = $settings->auth_mode === 'microsoft'
                ? app(MicrosoftSmtpOAuth::class)->accessToken($settings) : $settings->password;
            $config = [
                'transport'=>'smtp', 'scheme'=>$settings->encryption === 'ssl' ? 'smtps' : 'smtp',
                'host'=>$settings->host, 'port'=>$settings->port, 'username'=>$settings->username,
                'password'=>$password, 'timeout'=>15, 'require_tls'=>true,
            ];
            $from = $settings->from_address;
            $name = $settings->from_name;
        } else {
            $config = config('mail.mailers.smtp');
            $from = config('mail.from.address');
            $name = config('mail.from.name');
            if (empty($config['host']) || !$from) {
                throw new MailConfigurationException('Save an SMTP host and sender address in Settings > Email before sending a test.');
            }
        }
        if (($config['host'] ?? '') === BrevoSmtp::HOST) {
            $config['password'] = trim((string) ($config['password'] ?? ''));
            $errors = BrevoSmtp::validationErrors($config + [
                'encryption'=>($config['scheme'] ?? '') === 'smtps' ? 'ssl' : 'tls', 'from_address'=>$from,
            ]);
            if ($errors) throw new MailConfigurationException(reset($errors));
        }
        $mailer = Mail::build($config);
        if ($settings?->auth_mode === 'microsoft') {
            $transport = $mailer->getSymfonyTransport();
            if (!$transport instanceof EsmtpTransport) throw new MailConfigurationException('The Microsoft SMTP transport is unavailable.');
            // Do not send OAuth access tokens through LOGIN/PLAIN authentication.
            $transport->setAuthenticators([new XOAuth2Authenticator]);
        }
        $mailer->send($view, $data, fn ($message) => $message->from($from, $name)->to($to)->subject($subject));
    }

    public function failureMessage(\Throwable $error): string {
        if ($error instanceof MailConfigurationException) return $error->getMessage();
        if ($error instanceof \Illuminate\Contracts\Encryption\DecryptException) {
            return 'Saved SMTP credentials cannot be decrypted. Restore the original backend APP_KEY or re-enter and save the credentials.';
        }
        $message = strtolower($error->getMessage());
        $host = config('mail.mailers.smtp.host');
        try { $host = MailSetting::value('host') ?: $host; } catch (\Illuminate\Database\QueryException) {
            // Error reporting must still work if the settings database is unavailable.
        }
        if (str_contains($message, 'timed out') || str_contains($message, 'connection refused') ||
            str_contains($message, 'could not be established') || str_contains($message, 'getaddrinfo') ||
            str_contains($message, 'network is unreachable')) {
            if ($host === BrevoSmtp::HOST) {
                return 'Cannot connect to Brevo. Use smtp-relay.brevo.com with STARTTLS on port 587 or 2525, or SSL/TLS on port 465. Confirm your backend hosting allows outbound connections on that port.';
            }
            return 'Cannot connect to the SMTP server. Check its hostname and port, and confirm your hosting provider allows outbound SMTP connections.';
        }
        if (str_contains($message, 'certificate') || str_contains($message, 'starttls') || str_contains($message, 'crypto') || str_contains($message, 'ssl operation')) {
            return 'The secure SMTP connection failed. Use STARTTLS on port 587 or SSL/TLS on port 465, and check the server certificate and PHP OpenSSL support.';
        }
        if ($host === BrevoSmtp::HOST) {
            if (preg_match('/\b525\b/', $message) || str_contains($message, 'unauthorized ip')) {
                return 'Brevo blocked the backend server’s outbound IP. Add that IP to the authorized IP list in Brevo Settings > SMTP & API, then retry.';
            }
            if (str_contains($message, 'quota') || str_contains($message, 'credit') || str_contains($message, 'limit exceeded') || preg_match('/\b452\b/', $message)) {
                return 'Brevo’s sending credits or limit have been reached. Check the transactional email quota in Brevo before retrying.';
            }
            if (str_contains($message, 'not activated') || str_contains($message, 'not yet activated') || str_contains($message, 'suspended')) {
                return 'Brevo transactional sending is inactive or suspended. Check the account status in Brevo and complete its activation or contact Brevo support.';
            }
        }
        if (str_contains($message, 'authenticate') || str_contains($message, '535') || str_contains($message, '534') || str_contains($message, '5.7.57')) {
            return match ($host) {
                BrevoSmtp::HOST=>'Brevo rejected the login. Copy the exact Login and SMTP key from Brevo Settings > SMTP & API > SMTP. Use an SMTP key, not an API key or your account password; regenerate the SMTP key if necessary.',
                'smtp.gmail.com'=>'Gmail rejected the login. Use your full Gmail address and a 16-character App Password created after enabling 2-Step Verification.',
                'smtp-mail.outlook.com', 'smtp.office365.com'=>'Microsoft rejected the login. Select Microsoft sign-in, reconnect the same mailbox, and for Microsoft 365 ask the tenant administrator to enable Authenticated SMTP for that mailbox.',
                default=>'SMTP login rejected. Check the username and password; use an app password if your provider requires one.',
            };
        }
        if (str_contains($message, '550') || str_contains($message, '553') || str_contains($message, '5.7.60') || str_contains($message, 'sendasdenied')) {
            if ($host === BrevoSmtp::HOST) {
                return 'Brevo rejected the sender or recipient. Verify the sender email/domain in Brevo and check the recipient. The technical @smtp-brevo.com login cannot be a sender address. Check Brevo transactional logs for details.';
            }
            return 'The SMTP server rejected the sender or recipient. Use the authenticated mailbox as the sender, or an alias with Send As permission, and check the recipient address.';
        }
        if (str_contains($message, 'quota') || str_contains($message, 'limit exceeded') || str_contains($message, '452')) {
            return 'The email provider has reached its sending limit. Wait for the limit to reset or use another SMTP provider.';
        }
        return 'Email delivery failed. Check the saved SMTP configuration and your provider’s account permissions, then retry.';
    }
}
