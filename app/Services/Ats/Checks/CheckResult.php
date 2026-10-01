<?php

namespace App\Services\Ats\Checks;

use App\Services\Ats\Parsing\Confidence;

/**
 * The outcome of one §6 check: status, confidence, a message key with its parameters (S3 turns it into
 * EN/FR text) and up to 3 evidence lines of ≤ 200 characters. Points are assigned in S3, not here.
 */
final readonly class CheckResult
{
    /**
     * @param  array<string, scalar|null>  $params
     * @param  list<string>  $evidence
     */
    public function __construct(
        public string $id,
        public CheckStatus $status,
        public ?Confidence $confidence,
        public string $findingKey,
        public array $params = [],
        public array $evidence = [],
    ) {}

    public static function pass(string $id, string $key, array $params = [], ?Confidence $confidence = Confidence::High, array $evidence = []): self
    {
        return new self($id, CheckStatus::Pass, $confidence, $key, $params, self::trim($evidence));
    }

    public static function fail(string $id, string $key, array $params = [], ?Confidence $confidence = Confidence::High, array $evidence = []): self
    {
        return new self($id, CheckStatus::Fail, $confidence, $key, $params, self::trim($evidence));
    }

    public static function unverified(string $id, string $key, array $params = [], ?Confidence $confidence = null): self
    {
        return new self($id, CheckStatus::Unverified, $confidence, $key, $params);
    }

    /** @return list<string> */
    private static function trim(array $evidence): array
    {
        return array_slice(array_values(array_map(fn (string $e) => mb_substr(trim($e), 0, 200), array_filter($evidence, fn ($e) => trim((string) $e) !== ''))), 0, 3);
    }
}
