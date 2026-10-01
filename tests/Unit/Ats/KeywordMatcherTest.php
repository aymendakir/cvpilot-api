<?php

namespace Tests\Unit\Ats;

use App\Services\Ats\Keywords\CvIndex;
use App\Services\Ats\Keywords\JobKeyword;
use App\Services\Ats\Keywords\KeywordMatch;
use App\Services\Ats\Keywords\KeywordMatcher;
use App\Services\Ats\Keywords\KeywordReport;
use App\Services\Ats\Keywords\StuffingDetector;
use App\Services\Ats\Keywords\Taxonomy;
use App\Services\Ats\Sections\SectionDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Keyword matching, evidence, coverage and stuffing (SPEC-ats.md §6.2, §8.3, R2, R6). */
class KeywordMatcherTest extends TestCase
{
    private function keyword(string $term, string $kind = 'required'): JobKeyword
    {
        return new JobKeyword($term, $kind, (new Taxonomy)->groupOf($term), 1, $term);
    }

    /** @param list<string> $lines */
    private function cv(array $lines, string $language = 'en'): CvIndex
    {
        return new CvIndex((new SectionDetector)->detect($lines), $language);
    }

    private function matchOf(string $term, string $cvText, string $language = 'en'): KeywordMatch
    {
        return (new KeywordMatcher)->match($this->keyword($term), $this->cv([$cvText], $language));
    }

    /** @return array<string, array{0: string, 1: string, 2: ?string, 3: string}> */
    public static function spec83(): array
    {
        return [
            'JavaScript ← JS' => ['JavaScript', 'built UIs in JS', 'synonym', 'en'],
            'Kubernetes ← k8s' => ['Kubernetes', 'deployed services on k8s', 'synonym', 'en'],
            'CI/CD ← continuous integration' => ['CI/CD', 'set up continuous integration pipelines', 'synonym', 'en'],
            'Node.js ← Node' => ['Node.js', 'REST services in Node', 'synonym', 'en'],
            'C# exact' => ['C#', 'backend in C# and .NET', 'exact', 'en'],
            'managing teams ← managed a team' => ['managing teams', 'managed a team of 6', 'stem', 'en'],
            'testing ← tested' => ['testing', 'tested payment APIs', 'stem', 'en'],
            'gestion de projet ← gestion de projets' => ['gestion de projet', 'gestion de projets agiles', 'stem', 'fr'],
            'développement ← développé' => ['développement', 'développé une API', 'stem', 'fr'],
            'React ≠ reactive' => ['React', 'reactive programming with RxJS', null, 'en'],
            'Java ≠ JavaScript' => ['Java', 'JavaScript developer', null, 'en'],
            'Go ≠ going' => ['Go', 'going to the office daily', null, 'en'],
            'SQL ≠ PostgreSQL' => ['SQL', 'PostgreSQL administration', null, 'en'],
        ];
    }

    #[DataProvider('spec83')]
    public function test_spec_matcher_table(string $term, string $cvText, ?string $expected, string $language): void
    {
        $this->assertSame($expected, $this->matchOf($term, $cvText, $language)->matchType);
    }

    public function test_guarded_words_need_a_capital_and_short_terms_are_never_stemmed(): void
    {
        $this->assertNull($this->matchOf('Vue.js', 'Refonte en vue de la mise en production', 'fr')->matchType);
        $this->assertSame('synonym', $this->matchOf('Vue.js', 'Interfaces en Vue 3 et Symfony', 'fr')->matchType);
        $this->assertNull($this->matchOf('Go', 'ready to go live', 'en')->matchType);
        $this->assertSame('exact', $this->matchOf('Go', 'Services written in Go', 'en')->matchType);
        $this->assertNull($this->matchOf('Tableau', 'tableau de bord commercial', 'fr')->matchType);
        $this->assertNull($this->matchOf('AWS', 'awsome tooling', 'en')->matchType);
        $this->assertNull($this->matchOf('Laravel', 'Laravels everywhere', 'other')->matchType, 'no stemming for other languages');
    }

    public function test_slash_lists_are_split_but_short_slash_terms_stay_whole(): void
    {
        $this->assertSame('exact', $this->matchOf('Docker', 'Docker/Kubernetes', 'en')->matchType);
        $this->assertSame('exact', $this->matchOf('CSS', 'HTML/CSS', 'en')->matchType);
        $this->assertSame('exact', $this->matchOf('CI/CD', 'CI/CD with GitLab', 'en')->matchType);
    }

    public function test_evidence_found_in_and_matched_as(): void
    {
        $cv = $this->cv([
            'Samir Benali',
            'Backend developer working with Laravel.',
            'Work Experience',
            'Backend Developer, Atlas, Rabat',
            '• Built a RESTful API in Laravel for the mobile app.',
            '• Moved the Laravel apps to Docker.',
            'Skills',
            'Laravel, MySQL',
        ]);
        $matcher = new KeywordMatcher;

        $laravel = $matcher->match($this->keyword('Laravel'), $cv);
        $this->assertSame('exact', $laravel->matchType);
        $this->assertSame(['experience', 'skills', 'other'], $laravel->foundIn);
        $this->assertSame(['• Built a RESTful API in Laravel for the mobile app.', '• Moved the Laravel apps to Docker.'], $laravel->evidence);

        $rest = $matcher->match($this->keyword('REST APIs'), $cv);
        $this->assertSame(['synonym', 'RESTful API'], [$rest->matchType, $rest->matchedAs]);

        $mysql = $matcher->match($this->keyword('MySQL'), $cv);
        $this->assertTrue($mysql->skillsOnly());
        $this->assertSame(['Laravel, MySQL'], $mysql->evidence);

        $long = $matcher->match($this->keyword('Docker'), $this->cv([str_repeat('word ', 60).'Docker']));
        $this->assertLessThanOrEqual(200, mb_strlen($long->evidence[0]));
    }

    public function test_coverage_is_weighted_and_needs_three_terms(): void
    {
        $cv = $this->cv(['PHP and Laravel developer']);
        $matcher = new KeywordMatcher;
        $report = new KeywordReport(array_map(fn ($k) => $matcher->match($k, $cv), [
            $this->keyword('PHP'), $this->keyword('Redis'), $this->keyword('Laravel', 'preferred'), $this->keyword('Kubernetes', 'preferred'),
        ]), []);

        $this->assertSame('ok', $report->status());
        $this->assertSame(0.5, $report->coverage()); // (2 + 1) / (2 + 2 + 1 + 1)
        $this->assertSame(['matched' => 1, 'total' => 2], $report->count('required'));
        $this->assertSame(['Redis', 'Kubernetes', 'PHP', 'Laravel'], array_column($report->toArray()['items'], 'term'));

        $short = new KeywordReport([$matcher->match($this->keyword('PHP'), $cv)], []);
        $this->assertSame('insufficient_job_description', $short->status());
        $this->assertNull($short->coverage());
    }

    public function test_stuffing_counts_synonyms_and_starts_above_ten(): void
    {
        $detector = new StuffingDetector;
        $keywords = [$this->keyword('Laravel'), $this->keyword('Kubernetes')];

        $this->assertSame([], $detector->detect($keywords, $this->cv(array_fill(0, 10, 'Laravel'))));
        $this->assertSame(
            [['term' => 'Laravel', 'count' => 11], ['term' => 'Kubernetes', 'count' => 12]],
            $detector->detect($keywords, $this->cv([...array_fill(0, 11, 'Laravel'), ...array_fill(0, 6, 'k8s and Kubernetes')])),
        );
    }
}
