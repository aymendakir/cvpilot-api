<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Production logs to stderr (no persistent disk); the secret redaction must apply there too. */
class StderrLoggingTest extends TestCase
{
    private string $stream;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stream = sys_get_temp_dir().'/cvpilot-stderr-'.bin2hex(random_bytes(6)).'.log';
        // Same channel as production, writing to a file instead of php://stderr so the test can read it.
        config(['logging.default' => 'stderr', 'logging.channels.stderr.with.stream' => $this->stream]);
        Log::forgetChannel('stderr');
    }

    protected function tearDown(): void
    {
        @unlink($this->stream);
        parent::tearDown();
    }

    private function written(): string
    {
        return is_file($this->stream) ? (string) file_get_contents($this->stream) : '';
    }

    public function test_the_stderr_channel_exists_and_is_selected_by_the_env(): void
    {
        $process = new Process(['php', 'artisan', 'config:show', 'logging.default'], base_path(), ['APP_ENV' => 'local', 'LOG_CHANNEL' => 'stderr']);
        $process->mustRun();
        $this->assertStringContainsString('stderr', $process->getOutput());

        $process = new Process(['php', 'artisan', 'config:show', 'logging.default'], base_path(), ['APP_ENV' => 'local', 'LOG_CHANNEL' => false]);
        $process->mustRun();
        $this->assertStringContainsString('single', $process->getOutput());
    }

    public function test_errors_are_written_to_the_stream_without_provider_keys(): void
    {
        Route::middleware('web')->get('/api/_t/leak-stderr', fn () => throw new \RuntimeException('provider said no: https://x.example/v1?api_key=sk-FAKEOPENAIKEY0123456789 and AIzaSyD-FAKE-GEMINI-KEY-0123456789abcdef'));

        $this->getJson('/api/_t/leak-stderr', ['X-Request-Id' => 'trace-stderr-1'])->assertStatus(500);

        $log = $this->written();
        $this->assertStringContainsString('provider said no', $log);
        $this->assertStringContainsString('trace-stderr-1', $log);
        $this->assertStringContainsString('[REDACTED]', $log);
        $this->assertStringNotContainsString('sk-FAKEOPENAIKEY0123456789', $log);
        $this->assertStringNotContainsString('AIzaSyD-FAKE-GEMINI-KEY-0123456789abcdef', $log);
    }

    public function test_warnings_reach_the_stream_and_debug_does_not(): void
    {
        Log::warning('retention run late');
        Log::debug('noise');

        $this->assertStringContainsString('retention run late', $this->written());
        $this->assertStringNotContainsString('noise', $this->written());
    }
}
