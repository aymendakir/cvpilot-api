<?php

namespace App\Services\Ats\Checks\Format;

use App\Services\Ats\Checks\CheckContext;
use App\Services\Ats\Checks\CheckResult;
use App\Services\Ats\Checks\StructureCheck;

/** §6 clean_characters: no icon/private-use glyphs and no letter-spaced headings (read from the text, so pasted text is judged too). */
final class CleanCharacters extends StructureCheck
{
    public function id(): string
    {
        return 'clean_characters';
    }

    public function run(CheckContext $context): CheckResult
    {
        $glyphs = $context->document->structure->glyphIssues;

        return $glyphs->detected
            ? CheckResult::fail($this->id(), 'glyphs', ['note' => $glyphs->note] + $this->scalars($glyphs->extra), $glyphs->confidence, $glyphs->samples)
            : CheckResult::pass($this->id(), 'ok', [], $glyphs->confidence);
    }
}
