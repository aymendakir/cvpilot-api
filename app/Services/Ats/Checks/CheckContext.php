<?php

namespace App\Services\Ats\Checks;

use App\Services\Ats\Parsing\ParsedDocument;
use App\Services\Ats\Sections\Sections;

/** What every check reads: the parsed document, its sections and the CV language (en|fr|other). */
final readonly class CheckContext
{
    public function __construct(
        public ParsedDocument $document,
        public Sections $sections,
        public string $language,
    ) {}
}
