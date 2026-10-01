<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;

/** Helpers for asserting that a /api/v1 route behaves like its deprecated legacy twin. */
trait ComparesRoutes
{
    /** Keys whose values legitimately differ between two requests. */
    private const VOLATILE = ['request_id', 'token', 'created_at', 'updated_at', 'last_seen_at', 'id', 'user_id', 'reference'];

    private function normalized(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = in_array($key, self::VOLATILE, true) ? '*' : $this->normalized($item);
        }

        return $out;
    }

    /** Same status and same JSON body, ignoring volatile values (ids, timestamps, request ids). */
    protected function assertSameResponse(TestResponse $legacy, TestResponse $v1, string $label = ''): void
    {
        $this->assertSame($legacy->getStatusCode(), $v1->getStatusCode(), "{$label}: status");
        $this->assertEquals(
            $this->normalized($legacy->json()),
            $this->normalized($v1->json()),
            "{$label}: body"
        );
    }

    protected function assertNotDeprecated(TestResponse $response): void
    {
        $response->assertHeaderMissing('Deprecation');
        $response->assertHeaderMissing('Link');
    }

    protected function assertDeprecated(TestResponse $response, ?string $successor = null): void
    {
        $response->assertHeader('Deprecation', 'true');
        if ($successor !== null) {
            $response->assertHeader('Link', "<{$successor}>; rel=\"successor-version\"");
        }
    }
}
