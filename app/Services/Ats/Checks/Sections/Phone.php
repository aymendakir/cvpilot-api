<?php

namespace App\Services\Ats\Checks\Sections;

use App\Services\Ats\Checks\Check;
use App\Services\Ats\Checks\CheckContext;
use App\Services\Ats\Checks\CheckResult;
use App\Services\Ats\Checks\Patterns;

/** §6 phone: a phone number (8–15 digits, international or local format) in the CV text. */
final class Phone implements Check
{
    public function id(): string
    {
        return 'phone';
    }

    public function run(CheckContext $context): CheckResult
    {
        $phone = Patterns::phone($context->document->text);

        return $phone !== null
            ? CheckResult::pass($this->id(), 'found', ['phone' => $phone])
            : CheckResult::fail($this->id(), 'missing');
    }
}
