<?php

namespace Tests\Feature\Api;

use App\Services\Ats\AtsAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** POST /api/v1/ats/analyses (SPEC-ats.md §5): the HTTP layer returns exactly what the analyzer builds. */
class AtsAnalysesTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private const DIR = __DIR__.'/../../fixtures/ats';

    private const URL = '/api/v1/ats/analyses';

    /** @return array<string, mixed> request body for a fixture CV (a .txt fixture is sent as pasted text) */
    public static function body(string $cv, ?string $job = null, array $extra = []): array
    {
        $body = str_ends_with($cv, '.txt')
            ? ['cv_text' => (string) file_get_contents(self::DIR."/{$cv}")]
            : ['file' => UploadedFile::fake()->createWithContent(basename($cv), (string) file_get_contents(self::DIR."/{$cv}"))];
        if ($job !== null) {
            $body['job_description'] = (string) file_get_contents(self::DIR."/{$job}");
        }

        return $body + $extra;
    }

    private function analyse(array $body)
    {
        return $this->signIn($this->makeUser())->post(self::URL, $body, ['Accept' => 'application/json']);
    }

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
    public function test_every_fixture_returns_the_analyzer_report(string $case): void
    {
        $expected = json_decode((string) file_get_contents(self::DIR."/expected/{$case}.json"), true);
        $response = $this->analyse(self::body($expected['cv'], $expected['job']))->assertOk();
        $body = $response->json();

        $jd = $expected['job'] === null ? null : (string) file_get_contents(self::DIR."/{$expected['job']}");
        $direct = str_ends_with($expected['cv'], '.txt')
            ? (new AtsAnalyzer)->analyzeText((string) file_get_contents(self::DIR."/{$expected['cv']}"), $jd)->toArray()
            : (new AtsAnalyzer)->analyzeFile(self::DIR."/{$expected['cv']}", basename($expected['cv']), $jd)->toArray();
        unset($body['generated_at'], $direct['generated_at']);
        $this->assertEquals($direct, $body, "{$case}: HTTP body = analyzer output");

        $this->assertSame([$expected['score'], $expected['raw_score'], $expected['grade']], [$body['score'], $body['raw_score'], $body['grade']]);
        $this->assertSame($expected['top_suggestions'], array_map(fn ($s) => ['id' => $s['id'], 'impact_points' => $s['impact_points']],
            array_slice($body['suggestions'], 0, count($expected['top_suggestions']))));
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function test_locale_and_include_text(): void
    {
        $fr = $this->analyse(self::body('cvs/clean-en.docx', null, ['locale' => 'fr', 'include_text' => '1']))->assertOk();
        $this->assertSame('fr', $fr->json('locale'));
        $this->assertStringStartsWith('Samir Benali', $fr->json('document.text'));
        $this->assertSame('clean-en.docx', $fr->json('document.file_name'));

        $en = $this->analyse(self::body('cvs/clean-fr.docx'))->assertOk();
        $this->assertSame('fr', $en->json('locale'), 'default = detected CV language');
        $this->assertArrayNotHasKey('text', $en->json('document'));
    }

    public function test_signed_out_is_401(): void
    {
        $this->post(self::URL, self::body('cvs/clean-en.docx'), ['Accept' => 'application/json'])
            ->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    }

    public function test_the_21st_analysis_in_a_minute_is_429(): void
    {
        $this->signIn($this->makeUser());
        $text = ['cv_text' => str_repeat('Built PHP services for clients. ', 5)];
        for ($i = 1; $i <= 20; $i++) {
            $this->post(self::URL, $text, ['Accept' => 'application/json'])->assertOk();
        }
        $this->post(self::URL, $text, ['Accept' => 'application/json'])
            ->assertStatus(429)->assertJsonPath('code', 'too_many_requests')->assertHeader('Retry-After');
    }
}
