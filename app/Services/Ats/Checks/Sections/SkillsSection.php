<?php

namespace App\Services\Ats\Checks\Sections;

final class SkillsSection extends SectionPresence
{
    protected function kind(): string
    {
        return 'skills';
    }
}
