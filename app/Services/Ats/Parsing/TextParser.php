<?php

namespace App\Services\Ats\Parsing;

/**
 * Pasted text and `.txt` uploads. There is no layout to inspect (structure checks become `unverified`,
 * §8.1 F9); glyph issues are still read from the text.
 */
final class TextParser implements DocumentParser
{
    public function __construct(private readonly GlyphInspector $glyphs = new GlyphInspector) {}

    public function parse(string $path): ParsedDocument
    {
        $text = (string) file_get_contents($path);
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        }

        return $this->parseText($text, filesize($path) ?: null);
    }

    public function parseText(string $text, ?int $sizeBytes = null): ParsedDocument
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        $lines = array_values(array_filter(array_map('rtrim', explode("\n", $text)), fn ($l) => trim($l) !== ''));
        $words = ParsedDocument::countWords($text);

        return new ParsedDocument(
            type: 'text',
            text: $text,
            lines: array_map(fn (string $l) => new Line($l), $lines),
            pages: null,
            wordCount: $words,
            textExtractable: $words > 0,
            structure: Structure::notInspected($this->glyphs->inspect($lines)),
            sizeBytes: $sizeBytes,
        );
    }
}
