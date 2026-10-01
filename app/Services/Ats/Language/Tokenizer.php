<?php

namespace App\Services\Ats\Language;

/**
 * Splits text into lower-case tokens (accents kept, see Normalizer::fold for comparisons).
 * Tech tokens stay whole: "c#", "c++", ".net", "node.js", "ci/cd", "a/b". Hyphens and apostrophes
 * separate words ("full-stack" → full, stack; "d'expérience" → d, expérience).
 */
final class Tokenizer
{
    private const PATTERN = '/(?<![\p{L}\p{N}])\.?[\p{L}\p{N}]+(?:[.\/][\p{L}\p{N}]+)*(?:\+\+|#)?/u';

    public function __construct(private readonly Normalizer $normalizer = new Normalizer) {}

    /** @return list<string> */
    public function tokens(string $text): array
    {
        preg_match_all(self::PATTERN, mb_strtolower($this->normalizer->display($text), 'UTF-8'), $m);

        return $m[0];
    }

    /** A token with something other than letters in it (c#, .net, node.js, ci/cd, b2b, php8). */
    public static function isTechnical(string $token): bool
    {
        return preg_match('/^\p{L}+$/u', $token) !== 1;
    }
}
