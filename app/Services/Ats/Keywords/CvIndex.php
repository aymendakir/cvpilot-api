<?php

namespace App\Services\Ats\Keywords;

use App\Services\Ats\Language\Stemmer;
use App\Services\Ats\Language\StopWords;
use App\Services\Ats\Sections\Sections;

/**
 * The CV lines prepared once for keyword matching: tokens with offsets, the section of each line, and
 * (for EN/FR CVs) the Snowball stems of the non-stop-word tokens.
 */
final class CvIndex
{
    /** @var list<array{text: string, section: string, tokens: list<array{text: string, key: string, start: int, end: int}>, stems: list<array{text: string, key: string, start: int, end: int}>}> */
    public readonly array $lines;

    public function __construct(
        Sections $sections,
        public readonly string $language,
        private readonly Stemmer $stemmer = new Stemmer,
        private readonly StopWords $stopWords = new StopWords,
    ) {
        $lines = [];
        foreach ($sections->lines as $i => $text) {
            $tokens = Tokens::of($text);
            $kind = $sections->kindOfLine($i);
            $lines[] = [
                'text' => $text,
                'section' => $kind === 'header' ? 'other' : $kind,
                'tokens' => $tokens,
                'stems' => $this->stemmable() ? $this->stems($tokens) : [],
            ];
        }
        $this->lines = $lines;
    }

    /** Stemming is only defined for English and French CVs. */
    public function stemmable(): bool
    {
        return in_array($this->language, ['en', 'fr'], true);
    }

    /**
     * Tokens without stop words, each key replaced by its stem (offsets kept for the CV wording).
     *
     * @param  list<array{text: string, key: string, start: int, end: int}>  $tokens
     * @return list<array{text: string, key: string, start: int, end: int}>
     */
    public function stems(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $token) {
            if (! $this->stopWords->contains($token['key'], $this->language)) {
                $out[] = ['key' => $this->stemmer->stem($token['key'], $this->language)] + $token;
            }
        }

        return $out;
    }
}
