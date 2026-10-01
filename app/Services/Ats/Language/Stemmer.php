<?php

namespace App\Services\Ats\Language;

use Wamania\Snowball\Stemmer\Stemmer as SnowballStemmer;
use Wamania\Snowball\StemmerFactory;

/**
 * Snowball stems for English and French (wamania/php-stemmer). Stems are returned folded (no accents,
 * lower case). Technical tokens (c#, node.js, ci/cd) are returned unchanged. Whether short terms are
 * stemmed at all is the matcher's decision (S2), not this class's.
 */
final class Stemmer
{
    /** @var array<string, SnowballStemmer> */
    private array $stemmers = [];

    public function __construct(private readonly Normalizer $normalizer = new Normalizer) {}

    public function stem(string $token, string $language): string
    {
        $token = mb_strtolower($token, 'UTF-8');
        if (Tokenizer::isTechnical($token) || ! in_array($language, ['en', 'fr'], true)) {
            return $this->normalizer->fold($token);
        }
        $this->stemmers[$language] ??= StemmerFactory::create($language);

        return $this->normalizer->fold($this->stemmers[$language]->stem($token));
    }
}
