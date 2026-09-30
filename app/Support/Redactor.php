<?php

namespace App\Support;

/**
 * Removes credentials from text that may be stored, logged or shown to
 * people (provider error messages, exception messages).
 */
final class Redactor
{
    public const MASK = '[REDACTED]';

    /** Secrets shorter than this are not replaced by value, to avoid mangling ordinary text. */
    private const MIN_SECRET_LENGTH = 8;

    /**
     * @param  array<int, string|null>  $secrets  exact values known to be secret (e.g. the integration key)
     */
    public static function scrub(?string $text, array $secrets = []): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen($secret) >= self::MIN_SECRET_LENGTH) {
                $text = str_replace($secret, self::MASK, $text);
            }
        }

        $patterns = [
            // ?key=..., &api_key=..., ?token=... in URLs and query strings
            '/([?&;](?:key|api[_-]?key|apikey|access[_-]?token|token|secret|password|client[_-]?secret)=)[^&\s"\']+/i' => '$1'.self::MASK,
            // Authorization / API key headers, with or without a Bearer prefix
            '/\b(authorization|x-api-key|x-goog-api-key|api-key)(\s*[:=]\s*)(?:Bearer\s+)?[^\s,;"\']+/i' => '$1$2'.self::MASK,
            // Jooble puts the API key in the URL path
            '/(jooble\.org\/api\/)[^\s\/?"\']+/i' => '$1'.self::MASK,
            // Bare bearer tokens
            '/\bBearer\s+[A-Za-z0-9._~+\/=-]{8,}/i' => 'Bearer '.self::MASK,
            // Well-known provider key shapes
            '/(?<![A-Za-z0-9])sk-[A-Za-z0-9_-]{10,}/' => self::MASK,
            '/(?<![A-Za-z0-9])AIza[0-9A-Za-z_-]{20,}/' => self::MASK,
            '/(?<![A-Za-z0-9])gsk_[A-Za-z0-9]{16,}/' => self::MASK,
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $text) ?? self::MASK;
    }
}
