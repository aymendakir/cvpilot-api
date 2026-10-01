<?php

namespace App\Services\Ats\Checks\Format;

use App\Services\Ats\Checks\Check;
use App\Services\Ats\Checks\CheckContext;
use App\Services\Ats\Checks\CheckResult;

/** §6 file_supported: a PDF or DOCX of at most 5 MB. Pasted text has no file to judge. */
final class FileSupported implements Check
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public function id(): string
    {
        return 'file_supported';
    }

    public function run(CheckContext $context): CheckResult
    {
        $doc = $context->document;
        if ($doc->type === 'text') {
            return CheckResult::unverified($this->id(), 'not_inspected', ['type' => 'text']);
        }
        $mb = $doc->sizeBytes === null ? null : round($doc->sizeBytes / 1048576, 1);
        if ($doc->sizeBytes !== null && $doc->sizeBytes > self::MAX_BYTES) {
            return CheckResult::fail($this->id(), 'too_large', ['type' => $doc->type, 'megabytes' => $mb]);
        }

        return CheckResult::pass($this->id(), 'ok', ['type' => $doc->type, 'megabytes' => $mb]);
    }
}
