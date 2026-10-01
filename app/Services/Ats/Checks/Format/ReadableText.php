<?php

namespace App\Services\Ats\Checks\Format;

use App\Services\Ats\Checks\Check;
use App\Services\Ats\Checks\CheckContext;
use App\Services\Ats\Checks\CheckResult;

/** §6 readable_text: extractable text, ≥ 40 words, replacement characters ≤ 1 % of characters. */
final class ReadableText implements Check
{
    public function id(): string
    {
        return 'readable_text';
    }

    public function run(CheckContext $context): CheckResult
    {
        $doc = $context->document;
        if (! $doc->textExtractable) {
            return CheckResult::fail($this->id(), 'no_text', ['type' => $doc->type]);
        }
        $chars = max(1, mb_strlen($doc->text));
        $bad = substr_count($doc->text, "\u{FFFD}");
        if ($bad / $chars > 0.01) {
            return CheckResult::fail($this->id(), 'garbled', ['percent' => round(100 * $bad / $chars, 1)]);
        }
        if ($doc->wordCount < 40) {
            return CheckResult::fail($this->id(), 'too_short', ['words' => $doc->wordCount]);
        }

        return CheckResult::pass($this->id(), 'ok', ['words' => $doc->wordCount]);
    }
}
