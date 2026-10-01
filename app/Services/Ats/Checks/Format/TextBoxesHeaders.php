<?php

namespace App\Services\Ats\Checks\Format;

use App\Services\Ats\Checks\CheckContext;
use App\Services\Ats\Checks\CheckResult;
use App\Services\Ats\Checks\StructureCheck;
use App\Services\Ats\Parsing\Confidence;

/**
 * §6 text_boxes_headers: no content in text boxes, and the email/phone are not only in a page header or
 * footer. PDFs have no text boxes (n/a), so only their repeated header/footer is judged.
 */
final class TextBoxesHeaders extends StructureCheck
{
    public function id(): string
    {
        return 'text_boxes_headers';
    }

    public function run(CheckContext $context): CheckResult
    {
        $s = $context->document->structure;
        if (! $s->inspected) {
            return CheckResult::unverified($this->id(), 'not_inspected', ['type' => $context->document->type]);
        }
        if ($s->textBoxes->detected === true) {
            return CheckResult::fail($this->id(), 'text_boxes', ['count' => $s->textBoxes->extra['count'] ?? null], $s->textBoxes->confidence, $s->textBoxes->samples);
        }
        if ($s->headerFooter->detected === true && ($s->headerFooter->extra['contact_only_there'] ?? false)) {
            return $s->headerFooter->confidence === Confidence::Low
                ? CheckResult::unverified($this->id(), 'low_confidence', [], Confidence::Low)
                : CheckResult::fail($this->id(), 'contact_in_header', [], $s->headerFooter->confidence, $s->headerFooter->samples);
        }

        return CheckResult::pass($this->id(), 'ok', [], $s->headerFooter->confidence ?? Confidence::High);
    }
}
