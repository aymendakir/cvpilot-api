<?php

namespace App\Services\Ats\Checks\Sections;

use App\Services\Ats\Checks\Check;
use App\Services\Ats\Checks\CheckContext;
use App\Services\Ats\Checks\CheckResult;
use App\Services\Ats\Checks\Patterns;

/** §6 email: a valid email address in the CV text. */
final class Email implements Check
{
    public function id(): string
    {
        return 'email';
    }

    public function run(CheckContext $context): CheckResult
    {
        $email = Patterns::email($context->document->text);

        return $email !== null
            ? CheckResult::pass($this->id(), 'found', ['email' => $email])
            : CheckResult::fail($this->id(), 'missing');
    }
}
