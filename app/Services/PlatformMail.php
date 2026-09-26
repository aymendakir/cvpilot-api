<?php
namespace App\Services;

use App\Models\MailSetting;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

class PlatformMail {
    public function send(string $to, string $subject, string $view, array $data): void {
        [$mailer, $from, $name] = $this->configuredMailer();
        $mailer->send($view, $data, fn ($message) => $message->from($from, $name)->to($to)->subject($subject));
    }

    public function checkConnection(): void {
        [$mailer] = $this->configuredMailer();
        $transport = $mailer->getSymfonyTransport();
        if (!$transport instanceof EsmtpTransport) throw new MailConfigurationException('The SMTP transport is unavailable.');
        try {
            // start() negotiates TLS and authenticates; it does not submit a message.
            $transport->start();
        } finally {
            $transport->stop();
        }
    }

    private function configuredMailer(): array {
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
        return [$mailer, $from, $name];
    }

    public function failureResponse(\Throwable $error, string $operation): array {
        $root = $error;
        for ($depth = 0; $depth < 10 && $root->getPrevious(); $depth++) $root = $root->getPrevious();
        $reflection = new \ReflectionClass($root);
        $type = $reflection->isAnonymous() ? 'InternalError' : $reflection->getShortName();
        $diagnostic = [
            'reference'=>'smtp-'.bin2hex(random_bytes(6)), 'operation'=>$operation,
            'type'=>$type, 'source'=>basename($root->getFile()), 'line'=>$root->getLine(),
            'smtp_code'=>$root instanceof \Symfony\Component\Mailer\Exception\TransportExceptionInterface &&
                $root->getCode() >= 400 && $root->getCode() <= 599 ? (int) $root->getCode() : null,
        ];
        // Never log raw exception messages, traces or SMTP debug transcripts: they may contain credentials.
        try { \Illuminate\Support\Facades\Log::warning('SMTP diagnostic', $diagnostic); } catch (\Throwable) {
            // A logging failure must not hide the mail failure from the administrator.
        }
        $message = $this->failureMessage($error);
        if (str_starts_with($message, 'Email delivery failed.') || $root instanceof \Error ||
            $error instanceof \Illuminate\View\ViewException) {
            $message .= ' Backend detail: '.$type.' at '.$diagnostic['source'].':'.$diagnostic['line'].'.';
        }
        return ['message'=>$message.' Reference: '.$diagnostic['reference'].'.', 'diagnostic'=>$diagnostic];
    }

    public function failureMessage(\Throwable $error): string {
        if ($error instanceof \Illuminate\View\ViewException) {
            return 'The backend could not render the email template. Check the backend view files, installed PHP extensions and storage/framework/views permissions. Use Check connection to verify SMTP separately.';
        }
        if ($error instanceof \Illuminate\Database\QueryException) {
            return 'The backend could not read the saved SMTP settings. Check the database connection and apply pending backend migrations.';
        }
        if ($error instanceof \Symfony\Component\Mime\Exception\RfcComplianceException) {
            return 'An email address is invalid. Check the sender and the signed-in administrator’s recipient address.';
        }
        if ($error instanceof \Error) {
            return 'The backend encountered a PHP runtime error while preparing email. Check the deployed dependencies and PHP extensions using the diagnostic reference.';
        }
        if ($error instanceof MailConfigurationException) return $error->getMessage();
        if ($error instanceof \Illuminate\Contracts\Encryption\DecryptException) {
            return 'Saved SMTP credentials cannot be decrypted. Restore the original backend APP_KEY or re-enter and save the credentials.';
        }
        $message = strtolower($error->getMessage());
        $smtpCode = $error instanceof \Symfony\Component\Mailer\Exception\TransportExceptionInterface ? (int) $error->getCode() : 0;
        $host = config('mail.mailers.smtp.host');
        try { $host = MailSetting::value('host') ?: $host; } catch (\Illuminate\Database\QueryException) {
            // Error reporting must still work if the settings database is unavailable.
        }
        if (str_contains($message, 'timed out') || str_contains($message, 'connection refused') ||
            str_contains($message, 'could not be established') || str_contains($message, 'getaddrinfo') ||
            str_contains($message, 'network is unreachable') || str_contains($message, 'closed unexpectedly') ||
            str_contains($message, 'connection reset') || str_contains($message, 'broken pipe') ||
            str_contains($message, 'unable to read from connection') || str_contains($message, 'unable to write bytes') ||
            str_contains($message, 'got empty code')) {
            if ($host === BrevoSmtp::HOST) {
                return 'Cannot connect to Brevo. Use smtp-relay.brevo.com with STARTTLS on port 587 or 2525, or SSL/TLS on port 465. Confirm your backend hosting allows outbound connections on that port.';
            }
            return 'Cannot connect to the SMTP server. Check its hostname and port, and confirm your hosting provider allows outbound SMTP connections.';
        }
        if ($smtpCode === 504 || str_contains($message, 'failed to find an authenticator')) {
            return 'The SMTP server did not offer a supported login method. Check the server hostname, authentication mode and TLS settings.';
        }
        if (str_contains($message, 'certificate') || str_contains($message, 'starttls') || str_contains($message, 'crypto') || str_contains($message, 'ssl operation')) {
            return 'The secure SMTP connection failed. Use STARTTLS on port 587 or SSL/TLS on port 465, and check the server certificate and PHP OpenSSL support.';
        }
        if ($host === BrevoSmtp::HOST) {
            if ($smtpCode === 525 || preg_match('/\b525\b/', $message) || str_contains($message, 'unauthorized ip')) {
                return 'Brevo blocked the backend server’s outbound IP. Add that IP to the authorized IP list in Brevo Settings > SMTP & API, then retry.';
            }
            if (str_contains($message, 'quota') || str_contains($message, 'credit') || str_contains($message, 'limit exceeded') || preg_match('/\b452\b/', $message)) {
                return 'Brevo’s sending credits or limit have been reached. Check the transactional email quota in Brevo before retrying.';
            }
            if (str_contains($message, 'not activated') || str_contains($message, 'not yet activated') || str_contains($message, 'suspended')) {
                return 'Brevo transactional sending is inactive or suspended. Check the account status in Brevo and complete its activation or contact Brevo support.';
            }
        }
        if (in_array($smtpCode, [530, 534, 535, 538], true) || str_contains($message, 'authenticate') || str_contains($message, '535') || str_contains($message, '534') || str_contains($message, '5.7.57')) {
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
        if ($error instanceof \Symfony\Component\Mailer\Exception\TransportExceptionInterface) {
            return 'The SMTP server interrupted or rejected the mail transaction'.($smtpCode >= 400 && $smtpCode <= 599 ? ' (SMTP '.$smtpCode.')' : '').'. Use Check connection, then check the provider’s transactional logs and account status.';
        }
        return 'Email delivery failed. Use Check connection to separate SMTP connection failures from backend email preparation errors.';
    }
}
