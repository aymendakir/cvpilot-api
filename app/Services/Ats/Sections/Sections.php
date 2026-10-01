<?php

namespace App\Services\Ats\Sections;

/**
 * The CV split into sections (SPEC-ats.md §6 B). Every line belongs to exactly one section:
 * `header` (before the first heading), `experience`, `education`, `skills` or `other`.
 */
final readonly class Sections
{
    public const KINDS = ['header', 'experience', 'education', 'skills', 'other'];

    /**
     * @param  list<string>  $lines  the document lines, in reading order
     * @param  list<string>  $sectionOf  section kind of each line (same index); a heading line has the kind it opens
     * @param  array<string, array{text: string, line: int}>  $headings  first heading per kind; `line` is 1-based
     * @param  list<int>  $headingLines  indexes of every heading line
     */
    public function __construct(
        public array $lines,
        public array $sectionOf,
        public array $headings,
        public array $headingLines,
    ) {}

    /** `•`, `-`, `*`, `▪`, `–`, `►`, `✓`, a numbered item, or a symbol-font (private-use) bullet, then a space. */
    public const MARKER = '/^(?:[•\-*▪▫◦●○■□►▸➢➤✓✔–—]|\p{Co}|\d{1,2}[.)])\s*/u';

    public function found(string $kind): bool
    {
        return isset($this->headings[$kind]);
    }

    /** Content lines (headings excluded) of a section, keyed by line index. @return array<int, string> */
    public function lines(string $kind): array
    {
        $out = [];
        foreach ($this->lines as $i => $line) {
            if ($this->sectionOf[$i] === $kind && ! in_array($i, $this->headingLines, true) && trim($line) !== '') {
                $out[$i] = $line;
            }
        }

        return $out;
    }

    public function kindOfLine(int $index): string
    {
        return $this->sectionOf[$index] ?? 'other';
    }

    /**
     * Experience bullets. A bullet starts with a list marker; following lines that continue it (a PDF
     * wraps one bullet over several lines) are joined to it. Without any marker in the section, lines of
     * ≥ 6 words that are not a date line count as bullets (Canva draws its markers as shapes).
     *
     * @return list<string>
     */
    public function bullets(): array
    {
        $lines = $this->lines('experience');
        $bullets = [];
        $open = null;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match(self::MARKER, $trimmed, $m)) {
                $bullets[] = trim(mb_substr($trimmed, mb_strlen($m[0])));
                $open = array_key_last($bullets);

                continue;
            }
            // A wrapped continuation of the open bullet starts lower-case (PDF line breaks).
            if ($open !== null && preg_match('/^\p{Ll}/u', $trimmed)) {
                $bullets[$open] .= ' '.$trimmed;

                continue;
            }
            $open = null;
        }
        if ($bullets !== []) {
            return $bullets;
        }

        return array_values(array_filter(
            $lines,
            fn (string $l) => count(preg_split('/\s+/u', trim($l))) >= 6 && ! DateRanges::containsRange($l),
        ));
    }
}
