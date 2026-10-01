<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErrorLoggingTest extends TestCase
{
    use RefreshDatabase;

    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = sys_get_temp_dir().'/cvpilot-log-'.bin2hex(random_bytes(6)).'.log';
        config(['logging.channels.single.path' => $this->logFile]);
        Log::forgetChannel('single');
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
        parent::tearDown();
    }

    private function logContents(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    public function test_an_unhandled_exception_is_logged_with_the_request_id_and_the_real_message(): void
    {
        Route::middleware('web')->get('/api/_t/boom', fn () => throw new \RuntimeException('database exploded for real'));

        $response = $this->getJson('/api/_t/boom', ['X-Request-Id' => 'trace-abc-12345'])->assertStatus(500);

        $this->assertSame('trace-abc-12345', $response->json('request_id'));
        $log = $this->logContents();
        $this->assertStringContainsString('database exploded for real', $log);
        $this->assertStringContainsString('trace-abc-12345', $log);
        $this->assertStringNotContainsString('database exploded for real', $response->getContent());
    }

    public function test_a_5xx_http_error_is_logged_with_its_real_message_and_request_id(): void
    {
        Route::middleware('web')->get('/api/_t/down', fn () => abort(503, 'Upstream detail for operators'));

        $this->getJson('/api/_t/down', ['X-Request-Id' => 'trace-def-67890'])->assertStatus(503);

        $log = $this->logContents();
        $this->assertStringContainsString('Upstream detail for operators', $log);
        $this->assertStringContainsString('trace-def-67890', $log);
        $this->assertStringContainsString('upstream_unavailable', $log);
    }

    public function test_client_errors_are_not_logged_as_server_errors(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401);
        $this->getJson('/api/nope')->assertStatus(404);

        $this->assertSame('', $this->logContents());
    }
}
