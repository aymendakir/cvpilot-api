<?php

namespace Tests\Feature\Ats;

use App\Services\Ats\Checks\CheckRunner;
use App\Services\Ats\Checks\CheckStatus;
use App\Services\Ats\Parsing\DocumentReader;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The golden check statuses (SPEC-ats.md §8, tests/fixtures/ats/expected/*.json) reproduced by the real
 * pipeline: parse the fixture, run the 17 document checks, compare every status. Points are S3.
 */
class ChecksFixturesTest extends TestCase
{
    private const DIR = __DIR__.'/../../fixtures/ats';

    /** @return array<string, array{0: string}> */
    public static function cases(): array
    {
        $cases = [];
        foreach (json_decode((string) file_get_contents(self::DIR.'/manifest.json'), true)['cases'] as $case) {
            $cases[$case['case']] = [$case['case']];
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_every_expected_check_status_is_reproduced(string $case): void
    {
        $expected = json_decode((string) file_get_contents(self::DIR."/expected/{$case}.json"), true);
        $document = (new DocumentReader)->parseFile(self::DIR.'/'.$expected['cv'], basename($expected['cv']));
        $results = (new CheckRunner)->run($document);

        $this->assertSame(array_values(array_diff(array_keys($expected['checks']), ['keyword_coverage'])), array_keys($results), 'the 17 checks, in §6 order');
        foreach ($expected['checks'] as $id => $check) {
            if ($id === 'keyword_coverage') {
                continue;
            }
            $result = $results[$id];
            $this->assertSame($check['status'], $result->status->value, "{$case} {$id}: {$result->findingKey} ".json_encode($result->params));
        }
    }

    public function test_every_failure_on_the_fixtures_carries_a_message_key_and_fits_the_evidence_limits(): void
    {
        foreach (self::cases() as [$case]) {
            $expected = json_decode((string) file_get_contents(self::DIR."/expected/{$case}.json"), true);
            $document = (new DocumentReader)->parseFile(self::DIR.'/'.$expected['cv'], basename($expected['cv']));
            foreach ((new CheckRunner)->run($document) as $result) {
                $this->assertNotSame('', $result->findingKey);
                $this->assertLessThanOrEqual(3, count($result->evidence));
                foreach ($result->evidence as $line) {
                    $this->assertLessThanOrEqual(200, mb_strlen($line));
                }
                if ($result->status === CheckStatus::Fail) {
                    $this->assertNotNull($result->confidence, "{$case} {$result->id}: a failure has a confidence");
                }
            }
        }
    }
}
