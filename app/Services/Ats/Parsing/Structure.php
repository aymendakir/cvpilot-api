<?php

namespace App\Services\Ats\Parsing;

/**
 * The §4.1 structure signals of a document. For pasted text `inspected` is false and the layout
 * signals are not inspected, but glyph issues are still read from the text (§8.1 F9).
 */
final readonly class Structure
{
    public function __construct(
        public bool $inspected,
        public Detection $columns,
        public Detection $tables,
        public Detection $images,
        public Detection $textBoxes,
        public Detection $headerFooter,
        public Detection $glyphIssues,
    ) {}

    public static function notInspected(Detection $glyphIssues): self
    {
        $none = Detection::notInspected('Pasted text has no layout to inspect.');

        return new self(false, $none, $none, Detection::notInspected('Pasted text has no layout to inspect.', ['count' => 0, 'largest_area_pct' => null]), $none, Detection::notInspected('Pasted text has no layout to inspect.', ['contact_only_there' => false]), $glyphIssues);
    }
}
