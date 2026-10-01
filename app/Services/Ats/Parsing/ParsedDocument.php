<?php

namespace App\Services\Ats\Parsing;

/**
 * What `ats-parsing` hands to the checks (SPEC-ats.md §4). Holds the text and signals only: never the
 * file path, and nothing is written anywhere.
 */
final readonly class ParsedDocument
{
    /**
     * @param  'pdf'|'docx'|'text'  $type
     * @param  list<Line>  $lines
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $type,
        public string $text,
        public array $lines,
        public ?int $pages,
        public int $wordCount,
        public bool $textExtractable,
        public Structure $structure,
        public ?int $sizeBytes = null,
        public array $warnings = [],
    ) {}

    public static function countWords(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
    }
}
