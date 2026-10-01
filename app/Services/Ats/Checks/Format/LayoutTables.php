<?php

namespace App\Services\Ats\Checks\Format;

use App\Services\Ats\Checks\CheckContext;
use App\Services\Ats\Checks\CheckResult;
use App\Services\Ats\Checks\StructureCheck;

/** §6 layout_tables: no table containing text. PDF tables are low confidence, so they are unverified. */
final class LayoutTables extends StructureCheck
{
    public function id(): string
    {
        return 'layout_tables';
    }

    public function run(CheckContext $context): CheckResult
    {
        return $this->judge($context, $context->document->structure->tables, 'tables');
    }
}
