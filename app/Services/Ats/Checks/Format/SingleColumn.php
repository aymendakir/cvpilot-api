<?php

namespace App\Services\Ats\Checks\Format;

use App\Services\Ats\Checks\CheckContext;
use App\Services\Ats\Checks\CheckResult;
use App\Services\Ats\Checks\StructureCheck;

/** §6 single_column: no multi-column layout (§4.1). */
final class SingleColumn extends StructureCheck
{
    public function id(): string
    {
        return 'single_column';
    }

    public function run(CheckContext $context): CheckResult
    {
        return $this->judge($context, $context->document->structure->columns, 'columns');
    }
}
