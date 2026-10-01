<?php

namespace App\Services\Ats\Checks;

interface Check
{
    public function id(): string;

    public function run(CheckContext $context): CheckResult;
}
