<?php

namespace App\Services\Ats\Checks\Sections;

final class EducationSection extends SectionPresence
{
    protected function kind(): string
    {
        return 'education';
    }
}
