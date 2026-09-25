<?php
namespace App\Services;

class BrevoSmtp {
    public const HOST = 'smtp-relay.brevo.com';

    // Shared by saving settings and sending with existing database/environment credentials.
    public static function validationErrors(array $config): array {
        if (($config['host'] ?? '') !== self::HOST) return [];
        $errors = [];
        if (!in_array((int) ($config['port'] ?? 0), [587, 465, 2525], true)) {
            $errors['port'] = 'Brevo supports port 587 or 2525 with STARTTLS, or port 465 with SSL/TLS.';
        } elseif (($config['encryption'] ?? '') !== ((int) $config['port'] === 465 ? 'ssl' : 'tls')) {
            $errors['encryption'] = 'Use STARTTLS for Brevo port 587 or 2525, or SSL/TLS for port 465.';
        }
        if (!filter_var($config['username'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $errors['username'] = 'Copy the Login from Brevo Settings > SMTP & API > SMTP. The relay hostname is not the login.';
        }
        $password = trim((string) ($config['password'] ?? ''));
        if ($password === '' || str_starts_with(strtolower($password), 'xkeysib-')) {
            $errors['password'] = 'Enter an SMTP key from the SMTP tab in Brevo. An API key or your Brevo account password cannot authenticate SMTP.';
        }
        if (str_ends_with(strtolower((string) ($config['from_address'] ?? '')), '@smtp-brevo.com')) {
            $errors['from_address'] = 'Use a sender email verified in Brevo. The technical @smtp-brevo.com login cannot be used as the sender.';
        }
        return $errors;
    }
}
