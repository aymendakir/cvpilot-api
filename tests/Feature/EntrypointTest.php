<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The container entrypoint decides which role a container plays. `ENTRYPOINT_PLAN=1` makes it print
 * its decision and exit before touching anything, so the rules can be tested without Docker.
 */
class EntrypointTest extends TestCase
{
    /** @return array{0: int, 1: string} */
    private function plan(array $command, array $env = []): array
    {
        $process = new Process(['sh', base_path('docker-entrypoint.sh'), ...$command], base_path(), ['ENTRYPOINT_PLAN' => '1'] + $env);
        $process->run();

        return [$process->getExitCode(), trim($process->getOutput())];
    }

    /** @return array<string, array{0: array<int, string>, 1: array<string, string>, 2: string}> */
    public static function roles(): array
    {
        return [
            'web default' => [['apache2-foreground'], [], 'role=web migrate=yes scheduler=no'],
            'web with the scheduler' => [['apache2-foreground'], ['RUN_SCHEDULER' => 'true'], 'role=web migrate=yes scheduler=yes'],
            'web with migrations off' => [['apache2-foreground'], ['RUN_MIGRATIONS' => 'false'], 'role=web migrate=no scheduler=no'],
            'scheduler container' => [['php', 'artisan', 'schedule:work'], [], 'role=worker migrate=no scheduler=no'],
            'worker never migrates by default' => [['php', 'artisan', 'queue:work'], [], 'role=worker migrate=no scheduler=no'],
            'worker can be told to migrate' => [['php', 'artisan', 'migrate'], ['RUN_MIGRATIONS' => 'true'], 'role=worker migrate=yes scheduler=no'],
            'worker ignores RUN_SCHEDULER' => [['php', 'artisan', 'schedule:work'], ['RUN_SCHEDULER' => 'true'], 'role=worker migrate=no scheduler=no'],
            'scheduler flag must be exactly true' => [['apache2-foreground'], ['RUN_SCHEDULER' => 'yes'], 'role=web migrate=yes scheduler=no'],
        ];
    }

    #[DataProvider('roles')]
    public function test_the_entrypoint_picks_the_right_role(array $command, array $env, string $expected): void
    {
        [$exit, $output] = $this->plan($command, $env);

        $this->assertSame(0, $exit);
        $this->assertSame($expected, $output);
    }

    public function test_the_script_is_valid_shell(): void
    {
        $process = new Process(['sh', '-n', base_path('docker-entrypoint.sh')]);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function test_the_compose_file_runs_the_scheduler_as_its_own_service_without_migrating(): void
    {
        $compose = (string) file_get_contents(base_path('compose.yaml'));

        $this->assertMatchesRegularExpression('/^  scheduler:/m', $compose);
        $this->assertStringContainsString('schedule:work', $compose);
        $this->assertStringContainsString('RUN_MIGRATIONS: "false"', $compose);
        $this->assertStringContainsString('service_healthy', $compose);
        $this->assertStringContainsString('/up', $compose, 'the api healthcheck calls the health route');
    }
}
