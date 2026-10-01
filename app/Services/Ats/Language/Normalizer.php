<?php

namespace App\Services\Ats\Language;

use Normalizer as IntlNormalizer;
use Transliterator;

/**
 * Text normalization for matching (SPEC-ats.md §6.1 step 1). The display form keeps accents; the
 * folded form is what comparisons use, so "Expérience" and "experience" are equal.
 */
final class Normalizer
{
    private ?Transliterator $stripMarks = null;

    /** NFKC, typographic punctuation made plain, whitespace collapsed. */
    public function display(string $text): string
    {
        $text = (string) IntlNormalizer::normalize($text, IntlNormalizer::FORM_KC);
        $text = strtr($text, [
            "\u{2019}" => "'", "\u{2018}" => "'", "\u{201C}" => '"', "\u{201D}" => '"',
            "\u{2013}" => '-', "\u{2014}" => '-', "\u{2212}" => '-', "\u{00A0}" => ' ', "\u{202F}" => ' ',
        ]);

        return trim((string) preg_replace('/[ \t]+/u', ' ', $text));
    }

    /** Lower-cased, accents removed: the comparison form. */
    public function fold(string $text): string
    {
        $this->stripMarks ??= Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');

        return mb_strtolower((string) $this->stripMarks->transliterate($this->display($text)), 'UTF-8');
    }
}
