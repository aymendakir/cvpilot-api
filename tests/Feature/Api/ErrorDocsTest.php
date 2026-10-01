<?php

namespace Tests\Feature\Api;

use App\Exceptions\ErrorCode;
use Tests\TestCase;

/** docs/ERRORS.md is the contract for the frontend; keep it in sync with ErrorCode. */
class ErrorDocsTest extends TestCase
{
    /** @return array<string, array{status: int, message: string}> */
    private function documentedCodes(): array
    {
        $doc = (string) file_get_contents(base_path('docs/ERRORS.md'));
        preg_match('/<!-- error-codes:start -->(.*)<!-- error-codes:end -->/s', $doc, $block);
        $this->assertNotEmpty($block, 'docs/ERRORS.md must contain the error-codes markers');

        $codes = [];
        foreach (explode("\n", $block[1]) as $line) {
            if (preg_match('/^\|\s*(\d{3})\s*\|\s*`([a-z_]+)`\s*\|\s*(.+?)\s*\|$/', $line, $m)) {
                $codes[$m[2]] = ['status' => (int) $m[1], 'message' => $m[3]];
            }
        }

        return $codes;
    }

    public function test_the_documented_codes_match_the_enum_exactly(): void
    {
        $documented = $this->documentedCodes();

        $this->assertEqualsCanonicalizing(
            array_map(fn (ErrorCode $c) => $c->value, ErrorCode::cases()),
            array_keys($documented),
        );

        foreach (ErrorCode::cases() as $code) {
            $this->assertSame($code->status(), $documented[$code->value]['status'], $code->value);
            $this->assertSame($code->message(), $documented[$code->value]['message'], $code->value);
        }
    }
}
