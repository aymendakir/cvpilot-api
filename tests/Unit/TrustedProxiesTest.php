<?php

namespace Tests\Unit;

use App\Support\TrustedProxies;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    /** @return array<string, array{0: string|null, 1: array<int, string>|string}> */
    public static function values(): array
    {
        return [
            'unset trusts nothing' => [null, []],
            'empty trusts nothing' => ['', []],
            'blank trusts nothing' => ['  ', []],
            'star trusts the platform proxy' => ['*', '*'],
            'star with spaces' => [' * ', '*'],
            'one address' => ['10.0.0.1', ['10.0.0.1']],
            'a list with spaces and gaps' => ['10.0.0.1, 10.0.0.0/8 ,,172.16.0.1', ['10.0.0.1', '10.0.0.0/8', '172.16.0.1']],
        ];
    }

    #[DataProvider('values')]
    public function test_the_env_value_is_parsed_strictly(?string $env, array|string $expected): void
    {
        $this->assertSame($expected, TrustedProxies::parse($env));
    }
}
