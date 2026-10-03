<?php

namespace App\Services;

/**
 * The language a career assistant answers in (plan API-C). The values are the cover letter's. Without a
 * language the prompts are unchanged, so the answer follows the model (usually English).
 */
final class OutputLanguage
{
    public const RULE = 'nullable|in:English,French,Spanish,Arabic';

    /** The instruction appended to a prompt, or '' when no language was asked for. */
    public static function instruction(?string $language): string
    {
        if (! $language) {
            return '';
        }

        return "\n\nOUTPUT LANGUAGE: Write the whole answer in {$language}, headings included. Keep names, quoted source text and technical terms as they are.";
    }
}
