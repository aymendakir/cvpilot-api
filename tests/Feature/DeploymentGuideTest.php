<?php

namespace Tests\Feature;

use Tests\TestCase;

/** docs/DEPLOYMENT.md must not drift from the code: the variables it names exist, and the critical ones are documented. */
class DeploymentGuideTest extends TestCase
{
    private function guide(): string
    {
        return (string) file_get_contents(base_path('docs/DEPLOYMENT.md'));
    }

    /** @return array<int, string> variable names in the first column of the "### API" table */
    private function documentedApiVariables(): array
    {
        preg_match('/### API\n(.*?)\n### Frontend/s', $this->guide(), $section);
        preg_match_all('/^\| (.+?) \|/m', $section[1], $rows);

        $names = [];
        foreach ($rows[1] as $cell) {
            preg_match_all('/`([A-Z][A-Z0-9_]*)(?:\*)?`/', $cell, $found);
            array_push($names, ...$found[1]);
        }

        return array_values(array_unique($names));
    }

    /** @return array<int, string> variables the code or the example env actually knows */
    private function knownVariables(): array
    {
        $known = [];
        preg_match_all('/^#?\s*([A-Z][A-Z0-9_]*)=/m', (string) file_get_contents(base_path('.env.example')), $m);
        array_push($known, ...$m[1]);

        foreach (['app', 'config', 'bootstrap'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir)));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    preg_match_all("/env\('([A-Z][A-Z0-9_]*)'/", (string) file_get_contents($file->getPathname()), $m);
                    array_push($known, ...$m[1]);
                }
            }
        }
        preg_match_all('/\$\{?([A-Z][A-Z0-9_]*)/', (string) file_get_contents(base_path('docker-entrypoint.sh')), $m);
        array_push($known, ...$m[1]);

        return array_values(array_unique($known));
    }

    public function test_every_variable_the_guide_names_exists(): void
    {
        $documented = $this->documentedApiVariables();
        $this->assertGreaterThan(20, count($documented), 'the API table should be parsed');

        // MAIL_* is documented as a group; ADMIN_* belongs to the seeder.
        $known = $this->knownVariables();
        $ignore = ['MAIL_', 'ADMIN_NAME', 'ADMIN_EMAIL', 'ADMIN_PASSWORD'];
        $missing = array_values(array_filter($documented, fn ($name) => ! in_array($name, $known, true) && ! in_array($name, $ignore, true)));

        $this->assertSame([], $missing, 'documented but unknown to the code and .env.example');
    }

    public function test_the_production_critical_variables_are_documented(): void
    {
        $documented = $this->documentedApiVariables();

        foreach (['APP_URL', 'FRONTEND_URL', 'SESSION_DRIVER', 'CACHE_STORE', 'LOG_CHANNEL', 'SESSION_SAME_SITE', 'SESSION_SECURE_COOKIE', 'SESSION_DOMAIN', 'TRUSTED_PROXIES', 'RUN_SCHEDULER', 'RUN_MIGRATIONS'] as $name) {
            $this->assertContains($name, $documented, "{$name} must be in the guide");
        }
    }

    public function test_the_guide_covers_the_operational_facts(): void
    {
        $guide = $this->guide();

        $this->assertStringContainsString('ephemeral', $guide, 'storage/ is ephemeral on Sevalla');
        $this->assertStringContainsString('/api/admin/smtp/microsoft/callback', $guide, 'the Microsoft redirect URI');
        $this->assertStringContainsString('retention.healthy', $guide);
        $this->assertStringContainsString('SameSite=none', $guide);
        $this->assertStringContainsString('lax', $guide);
        $this->assertStringContainsString('never stored', $guide, 'the privacy note about uploaded originals');
        $this->assertMatchesRegularExpression('/Runbook: from `SameSite=none` to `lax`/', $guide);
    }

    public function test_the_env_example_documents_the_production_switches(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        foreach (['SESSION_DRIVER', 'CACHE_STORE', 'LOG_CHANNEL', 'TRUSTED_PROXIES', 'RUN_SCHEDULER'] as $name) {
            $this->assertMatchesRegularExpression("/^{$name}=/m", $example, "{$name} belongs in .env.example");
        }
        $this->assertStringContainsString('docs/DEPLOYMENT.md', $example);
    }
}
