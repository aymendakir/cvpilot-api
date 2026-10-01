<?php

namespace App\Services\Ats\Checks\Sections;

final class ExperienceSection extends SectionPresence
{
    protected function kind(): string
    {
        return 'experience';
    }
}
