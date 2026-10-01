<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Contract test (SPEC-ats.md §5.2, §8.4): every response of POST /api/v1/ats/analyses validates against
 * docs/ats-report.schema.json, and the numbers inside it are consistent.
 */
class AtsReportSchemaTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private const DIR = __DIR__.'/../../fixtures/ats';

    /** @return list<string> validation errors, empty when the body is valid */
    public static function violations(mixed $body): array
    {
        $result = (new Validator)->validate(
            json_decode((string) json_encode($body)),
            (string) file_get_contents(base_path('docs/ats-report.schema.json')),
        );
        if ($result->isValid()) {
            return [];
        }
        $errors = [];
        foreach ((new ErrorFormatter)->format($result->error()) as $path => $messages) {
            $errors[] = $path.': '.implode('; ', (array) $messages);
        }

        return $errors;
    }

    /** @return array<string, array{0: string, 1: ?string, 2: array<string, mixed>}> */
    public static function requests(): array
    {
        $requests = [];
        foreach (json_decode((string) file_get_contents(self::DIR.'/manifest.json'), true)['cases'] as $case) {
            $requests[$case['case']] = [$case['cv'], $case['job'], []];
        }

        return $requests + [
            'F3 in French with the text' => ['cvs/clean-en.docx', 'jobs/laravel-dev.txt', ['locale' => 'fr', 'include_text' => '1']],
            'F11 in English' => ['cvs/clean-fr.docx', 'jobs/dev-symfony-fr.txt', ['locale' => 'en']],
            'stuffing' => ['cvs/stuffing.docx', 'jobs/laravel-dev.txt', []],
            'two-column PDF in French' => ['cvs/two-column.pdf', null, ['locale' => 'fr']],
            'table PDF' => ['cvs/table-layout.pdf', 'jobs/sentences-en.txt', []],
            'job description with too few keywords' => ['cvs/clean-en.docx', null, ['job_description' => 'We are looking for someone friendly who knows PHP and enjoys working with our small team.']],
        ];
    }

    private function report(string $cv, ?string $job, array $extra): array
    {
        return $this->signIn($this->makeUser())
            ->post('/api/v1/ats/analyses', AtsAnalysesTest::body($cv, $job, $extra), ['Accept' => 'application/json'])
            ->assertOk()->json();
    }

    #[DataProvider('requests')]
    public function test_every_response_matches_the_schema_and_is_consistent(string $cv, ?string $job, array $extra): void
    {
        $body = $this->report($cv, $job, $extra);

        $this->assertSame([], self::violations($body));
        $this->assertSame($body['suggestions'] === [] ? [] : range(1, count($body['suggestions'])), array_column($body['suggestions'], 'rank'), 'ranks 1..n without gaps');
        foreach ($body['suggestions'] as $suggestion) {
            $this->assertSame($body['score'] === null, $suggestion['impact_points'] === null, 'impact null only without a score');
        }
        if ($body['raw_score'] !== null) {
            $earned = array_sum(array_column($body['categories'], 'earned'));
            $max = array_sum(array_column($body['categories'], 'max'));
            $this->assertSame($body['raw_score'], (int) floor(100 * $earned / $max + 0.5 + 1e-9), 'Σ earned / Σ max = raw_score');
            $this->assertLessThanOrEqual($body['raw_score'], $body['score']);
        }
        foreach ($body['categories'] as $category) {
            $applicable = array_filter($category['checks'], fn ($c) => $c['applicable']);
            $this->assertSame($category['max'], array_sum(array_column($applicable, 'max')));
            $this->assertSame($category['earned'], array_sum(array_column($applicable, 'earned')));
        }
    }

    public function test_the_schema_rejects_broken_bodies(): void
    {
        $valid = $this->report('cvs/two-column.pdf', 'jobs/laravel-dev-short.txt', []);
        $this->assertSame([], self::violations($valid));

        $broken = [
            'missing field' => fn (array $b) => array_diff_key($b, ['summary' => 1]),
            'extra field' => fn (array $b) => $b + ['debug' => true],
            'unknown grade' => fn (array $b) => ['grade' => 'excellent'] + $b,
            'score over 100' => fn (array $b) => ['score' => 101] + $b,
            'unknown check id' => function (array $b) {
                $b['categories'][0]['checks'][0]['id'] = 'photo';

                return $b;
            },
            'evidence too long' => function (array $b) {
                $b['categories'][0]['checks'][1]['evidence'] = [str_repeat('x', 201)];

                return $b;
            },
            'negative impact' => function (array $b) {
                $b['suggestions'][0]['impact_points'] = -1;

                return $b;
            },
            'bad match type' => function (array $b) {
                $b['keywords']['items'][0]['match_type'] = 'fuzzy';

                return $b;
            },
        ];
        foreach ($broken as $name => $break) {
            $this->assertNotSame([], self::violations($break($valid)), "{$name} must fail the schema");
        }
    }
}
