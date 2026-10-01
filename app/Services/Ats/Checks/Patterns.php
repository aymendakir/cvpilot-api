<?php

namespace App\Services\Ats\Checks;

/** Contact and quantity patterns shared by the checks. */
final class Patterns
{
    public const EMAIL = '/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)*\.\p{L}{2,}/u';

    /** A run of digits with phone separators; validated by phone() (8–15 digits, not a year range). */
    private const PHONE_RUN = '/(?<![\p{L}\d])\(?\+?\(?\d[\d\s().\/-]{6,}\d(?![\p{L}\d])/u';

    public static function email(string $text): ?string
    {
        return preg_match(self::EMAIL, $text, $m) ? $m[0] : null;
    }

    public static function phone(string $text): ?string
    {
        preg_match_all(self::PHONE_RUN, $text, $m);
        foreach ($m[0] as $run) {
            $digits = preg_replace('/\D/', '', $run);
            $isYearRange = preg_match('/^(?:19|20)\d{2}\s*[-–\/]\s*(?:19|20)\d{2}$/', trim($run));
            $isDate = preg_match('/^\d{1,2}[\/.-]\d{1,2}[\/.-]\d{2,4}$/', trim($run)) || preg_match('/^\d{1,2}[\/.]\d{4}\s*-\s*\d{1,2}[\/.]\d{4}$/', trim($run));
            if (strlen($digits) >= 8 && strlen($digits) <= 15 && ! $isYearRange && ! $isDate) {
                return trim($run);
            }
        }

        return null;
    }

    /** A measurable result: a number, a percentage or a currency amount, not counting years. */
    public static function quantified(string $line): bool
    {
        $withoutYears = preg_replace('/(?<!\d)(?:19|20)\d{2}(?!\d)/', '', $line);

        return preg_match('/\d|%|[€$£]|(?<!\p{L})(?:mad|dh|dhs|dirhams?|eur|usd|k€)(?!\p{L})/iu', (string) $withoutYears) === 1;
    }
}
