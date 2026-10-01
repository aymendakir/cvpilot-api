<?php

namespace App\Services\Ats\Language;

/**
 * en | fr | other (SPEC-ats.md §3 assumption 3) from the share of English and French stop words.
 * Anything that is clearly neither (Spanish, German, Arabic, too short) is "other": still analysed,
 * with exact matching only.
 */
final class LanguageDetector
{
    private const MIN_TOKENS = 20;

    private const MIN_SHARE = 0.08; // at least 8 % of the tokens are stop words of the language

    /**
     * Frequent function words of nearby languages that EN/FR lists partly share ("de", "en", "la"), so a
     * Spanish or German CV is not taken for French or English. Words that are EN/FR stop words ("il",
     * "y", "no", "per", "do") are left out on purpose.
     */
    private const OTHER_MARKERS = [
        'el', 'los', 'las', 'del', 'con', 'para', 'por', 'una', 'es', 'su', 'sus', 'mi', 'como', 'pero',           // es
        'der', 'die', 'das', 'und', 'mit', 'für', 'ist', 'ein', 'eine', 'nicht', 'von', 'zu', 'auf', 'im', 'sind',      // de
        'della', 'dei', 'nel', 'nella', 'sono', 'gli', 'degli', 'anche',                                  // it
        'o', 'os', 'da', 'das', 'dos', 'em', 'na', 'com', 'não', 'uma',                                    // pt
    ];

    public function __construct(
        private readonly Tokenizer $tokenizer = new Tokenizer,
        private readonly StopWords $stopWords = new StopWords,
    ) {}

    public function detect(string $text): string
    {
        $tokens = array_values(array_filter($this->tokenizer->tokens($text), fn ($t) => ! Tokenizer::isTechnical($t)));
        if (count($tokens) < self::MIN_TOKENS) {
            return 'other';
        }
        $hits = ['en' => 0, 'fr' => 0];
        foreach ($tokens as $token) {
            foreach (array_keys($hits) as $language) {
                if ($this->stopWords->contains($token, $language)) {
                    $hits[$language]++;
                }
            }
        }
        $foreign = count(array_filter($tokens, fn ($t) => in_array($t, self::OTHER_MARKERS, true)));
        arsort($hits);
        $best = array_key_first($hits);
        $share = $hits[$best] / count($tokens);
        $other = array_values($hits)[1];

        return $share >= self::MIN_SHARE && $hits[$best] >= 1.5 * $other && $hits[$best] >= 2 * $foreign ? $best : 'other';
    }
}
