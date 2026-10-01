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

    /**
     * Runs only the migrate-and-retry part of the entrypoint against a stub `php`. Each entry is [output, exit code]
     * for one `php artisan migrate` call; the last entry repeats.
     *
     * @param  array<int, array{0: string, 1: int}>  $calls
     * @return array{exit: int, output: string, calls: int}
     */
    private function migrate(array $calls, int $maxAttempts = 3): array
    {
        $dir = sys_get_temp_dir().'/cvpilot-stub-'.bin2hex(random_bytes(5));
        mkdir($dir);
        file_put_contents("{$dir}/php", "#!/bin/sh\nn=\$(cat \"\$STUB_DIR/count\" 2>/dev/null || echo 0)\nn=\$((n+1))\necho \$n > \"\$STUB_DIR/count\"\nf=\"\$STUB_DIR/\$n\"\n[ -f \"\$f.out\" ] || f=\"\$STUB_DIR/last\"\ncat \"\$f.out\"\nexit \"\$(cat \"\$f.code\")\"\n");
        chmod("{$dir}/php", 0755);
        foreach ($calls as $i => [$out, $code]) {
            $name = $i === array_key_last($calls) ? 'last' : (string) ($i + 1);
            file_put_contents("{$dir}/{$name}.out", $out);
            file_put_contents("{$dir}/{$name}.code", (string) $code);
            if ($name === 'last' && $i + 1 <= $maxAttempts) {
                file_put_contents("{$dir}/".($i + 1).'.out', $out);
                file_put_contents("{$dir}/".($i + 1).'.code', (string) $code);
            }
        }

        $process = new Process(['sh', base_path('docker-entrypoint.sh'), 'apache2-foreground'], base_path(), [
            'PATH' => $dir.':'.getenv('PATH'), 'STUB_DIR' => $dir, 'ENTRYPOINT_TEST' => 'migrate',
            'MIGRATE_RETRY_DELAY' => '0', 'MIGRATE_MAX_ATTEMPTS' => (string) $maxAttempts,
        ]);
        $process->run();
        $count = (int) @file_get_contents("{$dir}/count");
        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);

        return ['exit' => $process->getExitCode(), 'output' => $process->getOutput().$process->getErrorOutput(), 'calls' => $count];
    }

    public function test_a_clean_migration_runs_once(): void
    {
        $result = $this->migrate([['Nothing to migrate.', 0]]);

        $this->assertSame(0, $result['exit']);
        $this->assertSame(1, $result['calls']);
    }

    public function test_connection_errors_are_retried_until_the_database_answers(): void
    {
        $result = $this->migrate([
            ['SQLSTATE[HY000] [2002] Connection refused (Connection: mysql, SQL: select * from information_schema.tables)', 1],
            ['SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo for db failed: Name or service not known', 1],
            ['INFO  Running migrations.', 0],
        ], 5);

        $this->assertSame(0, $result['exit']);
        $this->assertSame(3, $result['calls']);
        $this->assertStringContainsString('Database connection failed; retrying', $result['output']);
    }

    /** @return array<string, array{0: string}> */
    public static function connectionErrors(): array
    {
        return [
            'refused' => ['SQLSTATE[HY000] [2002] Connection refused'],
            'dns' => ['SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo failed: Name or service not known'],
            'timeout' => ['SQLSTATE[HY000] [2002] Connection timed out'],
            'gone away' => ['SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'],
            'lost connection' => ['SQLSTATE[HY000] [2013] Lost connection to MySQL server during query'],
        ];
    }

    #[DataProvider('connectionErrors')]
    public function test_each_kind_of_connection_error_is_retried(string $error): void
    {
        $result = $this->migrate([[$error, 1], ['done', 0]]);

        $this->assertSame(0, $result['exit']);
        $this->assertSame(2, $result['calls']);
    }

    public function test_a_database_that_never_answers_gives_up_with_the_last_error(): void
    {
        $result = $this->migrate([['SQLSTATE[HY000] [2002] Connection refused', 1]], 3);

        $this->assertSame(1, $result['exit']);
        $this->assertSame(3, $result['calls']);
        $this->assertStringContainsString('Database unreachable after 3 attempts', $result['output']);
        $this->assertStringContainsString('Connection refused', $result['output']);
    }

    /** @return array<string, array{0: string}> */
    public static function realMigrationErrors(): array
    {
        return [
            'table already exists' => ["SQLSTATE[42S01]: Base table or view already exists: 1050 Table 'blog_posts' already exists (Connection: mysql, SQL: create table `blog_posts` (...))"],
            'duplicate key name' => ["SQLSTATE[42000]: Syntax error or access violation: 1061 Duplicate key name 'blog_posts_slug_unique'"],
            'access denied' => ["SQLSTATE[HY000] [1045] Access denied for user 'cvpilot'@'10.0.0.4' (using password: YES)"],
            'unknown database' => ["SQLSTATE[HY000] [1049] Unknown database 'cvpilot'"],
            'syntax' => ['SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax'],
            'php error' => ['PHP Fatal error: Class "App\\Missing" not found'],
        ];
    }

    #[DataProvider('realMigrationErrors')]
    public function test_any_other_migration_error_is_printed_once_and_stops_the_start(string $error): void
    {
        $result = $this->migrate([[$error, 1]], 5);

        $this->assertSame(1, $result['exit']);
        $this->assertSame(1, $result['calls'], 'no retry for an error that waiting cannot fix');
        $this->assertSame(1, substr_count($result['output'], 'Migration failed'), 'announced once');
        $this->assertSame(1, substr_count($result['output'], substr($error, 0, 30)), 'the error text appears once');
        $this->assertStringNotContainsString('Database is not ready', $result['output']);
        $this->assertStringNotContainsString('retrying', $result['output']);
    }
}
