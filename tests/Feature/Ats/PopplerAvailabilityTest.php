<?php

namespace Tests\Feature\Ats;

use App\Services\Ats\Parsing\Poppler;
use Tests\TestCase;

/**
 * poppler-utils is a required system dependency of the ATS PDF parser. This test fails (never skips)
 * when it is missing, locally, in CI and in the Docker image (docs/DEPLOYMENT.md).
 */
class PopplerAvailabilityTest extends TestCase
{
    public function test_the_poppler_binaries_are_installed(): void
    {
        $problems = app(Poppler::class)->problems();

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    public function test_a_missing_binary_is_reported_with_install_instructions(): void
    {
        config(['ats.poppler.pdftotext' => '/nonexistent/pdftotext']);

        $problems = app(Poppler::class)->problems();

        $line = collect($problems)->first(fn ($p) => str_starts_with($p, 'pdftotext '));
        $this->assertNotNull($line);
        $this->assertStringContainsString('pdftotext (/nonexistent/pdftotext) is missing', $line);
        $this->assertStringContainsString('apt-get install poppler-utils', $line);
    }
}
