<?php

namespace App\Services\Ats\Language;

/** EN/FR stop words from resources/ats/stopwords.{en,fr}.txt, compared in folded form. */
final class StopWords
{
    /** @var array<string, array<string, true>> */
    private array $lists = [];

    public function __construct(private readonly Normalizer $normalizer = new Normalizer) {}

    /** @return array<string, true> */
    public function of(string $language): array
    {
        if (! isset($this->lists[$language])) {
            $path = resource_path("ats/stopwords.{$language}.txt");
            $words = is_file($path) ? preg_split('/\R/u', (string) file_get_contents($path), -1, PREG_SPLIT_NO_EMPTY) : [];
            $words = array_filter(array_map('trim', $words), fn ($w) => $w !== '' && ! str_starts_with($w, '#'));
            $this->lists[$language] = array_fill_keys(array_map(fn ($w) => $this->normalizer->fold($w), $words), true);
        }

        return $this->lists[$language];
    }

    public function contains(string $token, string $language): bool
    {
        return isset($this->of($language)[$this->normalizer->fold($token)]);
    }
}
