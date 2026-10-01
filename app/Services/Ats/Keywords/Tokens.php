<?php

namespace App\Services\Ats\Keywords;

use App\Services\Ats\Language\Normalizer;

/**
 * Tokens of one line with their original spelling and byte offsets, so a match can be shown as the
 * CV wording ("RESTful API") and checked for capitals ("Go", "Vue"). Same token rules as
 * Language\Tokenizer, plus one: a slash token is split into its parts unless every part has
 * ≤ 3 characters ("ci/cd", "a/b", "ui/ux" stay whole; "html/css", "docker/kubernetes" are split).
 */
final class Tokens
{
    private const PATTERN = '/(?<![\p{L}\p{N}])\.?[\p{L}\p{N}]+(?:[.\/][\p{L}\p{N}]+)*(?:\+\+|#)?/u';

    private static ?Normalizer $normalizer = null;

    /**
     * @return list<array{text: string, key: string, start: int, end: int}> `key` is the folded form; offsets are bytes in `$line`
     */
    public static function of(string $line): array
    {
        self::$normalizer ??= new Normalizer;
        preg_match_all(self::PATTERN, $line, $m, PREG_OFFSET_CAPTURE);
        $tokens = [];
        foreach ($m[0] as [$text, $start]) {
            $parts = str_contains($text, '/') ? explode('/', $text) : [$text];
            if (count($parts) > 1 && max(array_map('mb_strlen', $parts)) <= 3) {
                $parts = [$text];
            }
            $offset = $start;
            foreach ($parts as $part) {
                if ($part !== '') {
                    $tokens[] = ['text' => $part, 'key' => self::$normalizer->fold($part), 'start' => $offset, 'end' => $offset + strlen($part)];
                }
                $offset += strlen($part) + 1;
            }
        }

        return $tokens;
    }

    /** The comparison key of a phrase: folded tokens joined by one space ("Test-Driven Development" → "test driven development"). */
    public static function key(string $phrase): string
    {
        return implode(' ', array_column(self::of($phrase), 'key'));
    }

    /**
     * Start indexes where `$keys` occurs as a token sequence (token boundaries respected: "java" is not
     * in "javascript"). With `$capitalised`, every matched token must start with a capital letter.
     *
     * @param  list<array{text: string, key: string, start: int, end: int}>  $tokens
     * @param  list<string>  $keys
     * @return list<int>
     */
    public static function find(array $tokens, array $keys, bool $capitalised = false): array
    {
        $n = count($keys);
        $found = [];
        if ($n === 0) {
            return $found;
        }
        for ($i = 0, $last = count($tokens) - $n; $i <= $last; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if ($tokens[$i + $j]['key'] !== $keys[$j]) {
                    continue 2;
                }
                if ($capitalised && preg_match('/^\p{Lu}/u', $tokens[$i + $j]['text']) !== 1) {
                    continue 2;
                }
            }
            $found[] = $i;
            $i += $n - 1; // occurrences do not overlap
        }

        return $found;
    }
}
