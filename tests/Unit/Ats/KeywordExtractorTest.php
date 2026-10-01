<?php

namespace Tests\Unit\Ats;

use App\Services\Ats\Keywords\JobKeyword;
use App\Services\Ats\Keywords\KeywordExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Job-description keyword extraction, rule (b′) (SPEC-ats.md §6.1 as built in S2). */
class KeywordExtractorTest extends TestCase
{
    /** @return array<string, string> term => kind */
    private function terms(string $jobDescription): array
    {
        $out = [];
        foreach ((new KeywordExtractor)->extract($jobDescription) as $keyword) {
            $this->assertInstanceOf(JobKeyword::class, $keyword);
            $out[$keyword->term] = $keyword->kind;
        }

        return $out;
    }

    /** @return array<string, array{0: string}> */
    public static function fixtureJobs(): array
    {
        return ['F3' => ['F3'], 'F4b' => ['F4b'], 'F11' => ['F11'], 'F13' => ['F13']];
    }

    #[DataProvider('fixtureJobs')]
    public function test_fixture_job_descriptions_give_exactly_the_expected_terms(string $case): void
    {
        $expected = json_decode((string) file_get_contents(base_path("tests/fixtures/ats/expected/{$case}.json")), true);
        $want = array_column($expected['keywords']['items'], 'kind', 'term');
        $job = base_path('tests/fixtures/ats/'.$expected['job']);

        $this->assertSame($want, $this->terms((string) file_get_contents($job)));
    }

    public function test_a_job_ad_written_in_sentences_gives_taxonomy_terms_only(): void
    {
        $terms = $this->terms((string) file_get_contents(base_path('tests/fixtures/ats/jobs/sentences-en.txt')));

        $this->assertSame([
            'REST APIs' => 'preferred', 'PostgreSQL' => 'preferred', 'Redis' => 'preferred', 'Figma' => 'preferred',
            'PHP' => 'required', 'Laravel' => 'required', 'TypeScript' => 'required', 'React' => 'required', 'SQL' => 'required',
            'Docker' => 'required', 'CI/CD' => 'required', 'GitHub Actions' => 'required',
            'AWS' => 'preferred', 'Kubernetes' => 'preferred', 'Terraform' => 'preferred',
        ], $terms);
    }

    public function test_list_items_are_split_and_cleaned(): void
    {
        $terms = $this->terms(implode("\n", [
            'Requirements: PHP, Laravel and Docker',
            '- Experience with Redis caching',
            '- 3+ years of experience',
            '- Communication skills',
            '• Bonne maîtrise de Symfony',
            '- Cloud platforms (AWS, GCP)',
            '- Team',
        ]));

        $this->assertSame([
            'PHP' => 'required', 'Laravel' => 'required', 'Docker' => 'required', 'Redis' => 'required',
            'Communication' => 'required', 'Symfony' => 'required', 'Cloud platforms' => 'required', 'AWS' => 'required', 'GCP' => 'required',
        ], $terms);
    }

    public function test_free_text_gives_taxonomy_terms_by_frequency_and_never_everyday_words(): void
    {
        $terms = $this->terms(implode("\n", [
            'We use Laravel for the API and Laravel Horizon for queues; some services are in Go.',
            'You will go to client sites, and React to incidents in the Spring release, en vue de la mise en production.',
            'Our dashboards run on Docker.',
        ]));

        $this->assertSame(['Laravel' => 'required', 'React' => 'preferred', 'Docker' => 'preferred'], $terms);
    }

    public function test_block_wording_and_kind_win_and_required_beats_preferred(): void
    {
        $terms = $this->terms(implode("\n", [
            'Backend developer (PHP, Kubernetes)',
            'Nice to have:',
            'k8s',
            'Requirements:',
            'php',
            'Kubernetes',
        ]));

        $this->assertSame(['k8s' => 'required', 'php' => 'required'], $terms);
    }

    public function test_paragraph_after_a_list_closes_the_block(): void
    {
        $terms = $this->terms(implode("\n", ['Requirements', '- PHP', '- Laravel', '', 'What we offer', 'A friendly team and a learning budget']));

        $this->assertSame(['PHP' => 'required', 'Laravel' => 'required'], $terms);
    }

    public function test_french_headings_open_blocks(): void
    {
        $terms = $this->terms(implode("\n", ['Compétences techniques :', 'Laravel', 'Gestion de projet', 'Ce serait un plus :', 'Vue.js']));

        $this->assertSame(['Laravel' => 'required', 'Gestion de projet' => 'required', 'Vue.js' => 'preferred'], $terms);
    }

    public function test_at_most_forty_terms_keeping_required_first(): void
    {
        $items = array_map(fn ($i) => "Skill number{$i}", range(1, 45));
        $text = "Requirements:\n".implode("\n", array_slice($items, 0, 30))."\nNice to have:\n".implode("\n", array_slice($items, 30));
        $keywords = (new KeywordExtractor)->extract($text);

        $this->assertCount(KeywordExtractor::MAX_TERMS, $keywords);
        $this->assertCount(30, array_filter($keywords, fn ($k) => $k->kind === 'required'));
    }
}
