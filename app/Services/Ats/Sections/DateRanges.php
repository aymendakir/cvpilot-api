<?php

namespace App\Services\Ats\Sections;

use App\Services\Ats\Language\Normalizer;

/**
 * Date ranges in experience lines (SPEC-ats.md §6 `dates`). Styles: `month` (EN/FR month name or
 * abbreviation + year), `numeric` (MM/YYYY or MM.YYYY), `year` (YYYY). An open end ("Present",
 * "Current", "aujourd'hui", "présent", "en cours", "actuel") fits any style.
 */
final class DateRanges
{
    private const MONTHS = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?'
        .'|janv(?:ier)?|fevr?(?:ier)?|mars|avr(?:il)?|mai|juin|juil(?:let)?|aout|sept(?:embre)?|octobre|novembre|dec(?:embre)?';

    private const OPEN_END = 'present|current|now|today|ongoing|aujourd\'?hui|present|en cours|actuel(?:le)?|a ce jour';

    /**
     * Ranges found in a line, each as [startStyle, endStyle|null] (null = open end).
     *
     * @return list<array{0: string, 1: ?string}>
     */
    public static function find(string $line): array
    {
        $text = (new Normalizer)->fold($line);
        $date = '(?<%1$sm>(?:'.self::MONTHS.')\.?\s+(?:19|20)\d{2})|(?<%1$sn>(?:0?[1-9]|1[0-2])[\/.](?:19|20)\d{2})|(?<%1$sy>(?:19|20)\d{2})';
        $pattern = '/(?<![\p{L}\d])(?:'.sprintf($date, 's').')\s*(?:-|to|a|au|until|jusqu\'?a)\s*(?:(?:'.sprintf($date, 'e').')|(?<open>'.self::OPEN_END.'))(?![\p{L}\d])/u';
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $ranges = [];
        foreach ($matches as $m) {
            $start = $m['sm'] !== null ? 'month' : ($m['sn'] !== null ? 'numeric' : 'year');
            $end = $m['open'] !== null ? null : ($m['em'] !== null ? 'month' : ($m['en'] !== null ? 'numeric' : 'year'));
            $ranges[] = [$start, $end];
        }

        return $ranges;
    }

    public static function containsRange(string $line): bool
    {
        return self::find($line) !== [];
    }
}
