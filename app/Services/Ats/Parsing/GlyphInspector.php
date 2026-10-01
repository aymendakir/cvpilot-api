<?php

namespace App\Services\Ats\Parsing;

/**
 * §4.1 glyph issues: private-use (icon font) characters, replacement characters and letter-spaced
 * headings.
 *
 * Symbol-font list bullets (Word/OpenOffice "Wingdings" bullets) are also private-use characters but
 * harmless (spike finding, real-5). A private-use character is treated as a bullet when it is the first
 * character of a line and the same character starts at least 3 lines: a list. Contact icons (envelope,
 * phone, pin) also sit at line starts but each appears once, so they still count.
 */
final class GlyphInspector
{
    private const BULLET_MIN_LINES = 3;

    /** @param  list<string>  $lines */
    public function inspect(array $lines): Detection
    {
        $leading = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*(\p{Co})/u', $line, $m)) {
                $leading[$m[1]] = ($leading[$m[1]] ?? 0) + 1;
            }
        }
        $bullets = array_keys(array_filter($leading, fn (int $n) => $n >= self::BULLET_MIN_LINES));

        $privateUse = $replacement = $letterSpaced = 0;
        $samples = [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            $first = mb_substr($trimmed, 0, 1);
            $body = in_array($first, $bullets, true) ? mb_substr($trimmed, 1) : $trimmed;
            $pua = preg_match_all('/\p{Co}/u', (string) $body);
            $bad = substr_count($line, "\u{FFFD}");
            $spaced = preg_match('/^(?:\p{L} ){3,}\p{L}(?: {2,}(?:\p{L} )*\p{L})*$/u', trim($line));
            if ($pua + $bad + $spaced > 0) {
                $samples[] = $line;
            }
            $privateUse += $pua;
            $replacement += $bad;
            $letterSpaced += $spaced;
        }
        $extra = ['private_use' => $privateUse, 'replacement' => $replacement, 'letter_spaced' => $letterSpaced, 'symbol_bullets' => count($bullets)];
        if ($privateUse + $replacement + $letterSpaced === 0) {
            return Detection::absent(Confidence::High, $extra);
        }
        $parts = array_filter([
            $privateUse ? "{$privateUse} icon or private-use character(s)" : null,
            $replacement ? "{$replacement} unreadable character(s)" : null,
            $letterSpaced ? "{$letterSpaced} letter-spaced heading(s)" : null,
        ]);

        return Detection::found(Confidence::High, implode(', ', $parts), $samples, $extra);
    }
}
