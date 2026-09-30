<?php

namespace Tests\Unit;

use App\Support\Redactor;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    public function test_known_secrets_are_removed_wherever_they_appear(): void
    {
        $text = 'Failed calling https://api.example.test/v1/x/SuperSecretValue123/run with token SuperSecretValue123';

        $out = Redactor::scrub($text, ['SuperSecretValue123']);

        $this->assertStringNotContainsString('SuperSecretValue123', $out);
        $this->assertStringContainsString('[REDACTED]', $out);
    }

    public function test_secret_query_parameters_are_redacted_without_knowing_the_value(): void
    {
        $out = Redactor::scrub('cURL error 28 for https://h.example/v1/m:generate?alt=json&key=AIzaSyFakeFakeFakeFake1234567&x=1');

        $this->assertStringNotContainsString('AIzaSyFakeFakeFakeFake1234567', $out);
        $this->assertStringContainsString('alt=json', $out);
        $this->assertStringContainsString('x=1', $out);
        $this->assertStringContainsString('key=[REDACTED]', $out);
    }

    public function test_common_credential_parameter_names_are_redacted(): void
    {
        foreach (['api_key', 'apikey', 'access_token', 'token', 'secret', 'password', 'client_secret'] as $name) {
            $out = Redactor::scrub("GET https://h.example/p?{$name}=abcdef123456&ok=1");
            $this->assertStringNotContainsString('abcdef123456', $out, $name);
            $this->assertStringContainsString('ok=1', $out);
        }
    }

    public function test_authorization_and_api_key_headers_are_redacted(): void
    {
        $out = Redactor::scrub('Authorization: Bearer abc.def.ghi-123 and x-api-key: sk-ant-abcdef123456 and x-goog-api-key: AIzaXYZ123456789');

        $this->assertStringNotContainsString('abc.def.ghi-123', $out);
        $this->assertStringNotContainsString('sk-ant-abcdef123456', $out);
        $this->assertStringNotContainsString('AIzaXYZ123456789', $out);
    }

    public function test_well_known_key_shapes_are_redacted_even_when_bare(): void
    {
        $out = Redactor::scrub('bad key sk-proj-AbCdEf1234567890xyz and AIzaSyA1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q and gsk_abcdefghijklmnop1234');

        $this->assertStringNotContainsString('sk-proj-AbCdEf1234567890xyz', $out);
        $this->assertStringNotContainsString('AIzaSyA1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q', $out);
        $this->assertStringNotContainsString('gsk_abcdefghijklmnop1234', $out);
    }

    public function test_jooble_style_path_secrets_are_redacted_by_value(): void
    {
        $url = 'https://jooble.org/api/0123abcd-4567-89ef-0123-456789abcdef';

        $out = Redactor::scrub("Timeout for {$url}", ['0123abcd-4567-89ef-0123-456789abcdef']);

        $this->assertStringNotContainsString('0123abcd-4567-89ef-0123-456789abcdef', $out);
        $this->assertStringContainsString('https://jooble.org/api/[REDACTED]', $out);
    }

    public function test_the_jooble_path_key_is_redacted_without_knowing_the_value(): void
    {
        $out = Redactor::scrub('cURL error 6 for https://jooble.org/api/0123abcd-4567-89ef-0123-456789abcdef');

        $this->assertSame('cURL error 6 for https://jooble.org/api/[REDACTED]', $out);
    }

    public function test_ordinary_text_is_unchanged(): void
    {
        $text = 'HTTP request returned status code 429: rate limit reached, retry in 20s (model gpt-4o-mini)';

        $this->assertSame($text, Redactor::scrub($text));
    }

    public function test_short_or_empty_secrets_are_ignored_to_avoid_mangling_text(): void
    {
        $text = 'public endpoint is public';

        $this->assertSame($text, Redactor::scrub($text, ['public', '', null]));
    }

    public function test_null_and_non_string_safe(): void
    {
        $this->assertSame('', Redactor::scrub(null));
    }
}
