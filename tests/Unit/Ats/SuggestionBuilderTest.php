<?php

namespace Tests\Unit\Ats;

use App\Services\Ats\Checks\CheckResult;
use App\Services\Ats\Keywords\JobKeyword;
use App\Services\Ats\Keywords\KeywordMatch;
use App\Services\Ats\Keywords\KeywordReport;
use App\Services\Ats\Scoring\Scorer;
use Tests\TestCase;

/** Suggestions, what-if impact and ranking (SPEC-ats.md R5, R6; S3 decisions 24–26). */
class SuggestionBuilderTest extends TestCase
{
    /** @return array<string, CheckResult> every document check passing, with overrides */
    private function results(array $overrides = []): array
    {
        $out = [];
        foreach (array_keys(array_diff_key(config('ats.checks'), ['keyword_coverage' => 1])) as $id) {
            $out[$id] = $overrides[$id] ?? CheckResult::pass($id, 'ok');
        }

        return $out;
    }

    private function match(string $term, string $kind, ?string $type, array $foundIn = ['experience']): KeywordMatch
    {
        return new KeywordMatch(new JobKeyword($term, $kind, null, 1, "line with {$term}"), $type, $type ? $term : null, $type ? $foundIn : [], $type ? ["• {$term}"] : []);
    }

    public function test_failed_checks_get_suggestions_and_unverified_checks_none(): void
    {
        $assessment = (new Scorer)->assess($this->results([
            'phone' => CheckResult::fail('phone', 'missing'),
            'images' => CheckResult::unverified('images', 'not_inspected', ['type' => 'text']),
        ]));

        $this->assertSame(['phone'], array_map(fn ($s) => $s->id, $assessment->suggestions));
        $this->assertSame(['minor', 'sections', 'phone', 'missing', 1], [$assessment->suggestions[0]->severity, $assessment->suggestions[0]->category,
            $assessment->suggestions[0]->checkId, $assessment->suggestions[0]->key, $assessment->suggestions[0]->rank]);
        $this->assertSame(5, $assessment->suggestions[0]->impactPoints); // 62/65 → 95 (images unverified); with phone → 100
    }

    public function test_keyword_limits_and_order(): void
    {
        $matches = [];
        foreach (range(1, 7) as $i) {
            $matches[] = $this->match("Req{$i}", 'required', null);
        }
        foreach (range(1, 5) as $i) {
            $matches[] = $this->match("Pref{$i}", 'preferred', null);
        }
        foreach (['Zeta', 'Alpha', 'Beta', 'Gamma'] as $term) {
            $matches[] = $this->match($term, 'preferred', 'exact', ['skills']);
        }
        $matches[] = $this->match('Delta', 'required', 'exact', ['skills']);
        $assessment = (new Scorer)->assess($this->results(), new KeywordReport($matches, [['term' => 'Laravel', 'count' => 12]]));
        $by = fn (string $check) => array_values(array_map(fn ($s) => $s->id, array_filter($assessment->suggestions, fn ($s) => $s->checkId === $check)));

        $required = array_values(array_filter($assessment->suggestions, fn ($s) => $s->checkId === 'keyword_missing' && $s->key === 'required'));
        $this->assertCount(5, $required);
        $this->assertSame(['keyword:req1', 'keyword:req2', 'keyword:req3', 'keyword:req4', 'keyword:req5'], array_map(fn ($s) => $s->id, $required));
        $this->assertSame('minor', $required[0]->severity);
        $this->assertCount(3 + 5, $by('keyword_missing'), '5 required + 3 preferred');
        $this->assertSame(['keyword_skills_only:alpha', 'keyword_skills_only:beta', 'keyword_skills_only:delta'], $by('keyword_skills_only'), 'required Delta kept, then Alpha, Beta; ranked by id at impact 0');
        $this->assertSame(['keyword_stuffing:laravel'], $by('keyword_stuffing'));
        $this->assertSame(range(1, count($assessment->suggestions)), array_map(fn ($s) => $s->rank, $assessment->suggestions));
    }

    public function test_impact_then_severity_then_id_with_a_binding_cap(): void
    {
        $matches = array_map(fn ($i) => $this->match("Tool{$i}", 'required', 'exact'), range(1, 10));
        $matches[] = $this->match('Zed', 'required', null);
        $matches[] = $this->match('Abc', 'preferred', null);
        // coverage 20/23 → 26 points; 64 + 26 = 90 raw, capped at 84 by the failed single_column
        $assessment = (new Scorer)->assess($this->results(['single_column' => CheckResult::fail('single_column', 'columns')]), new KeywordReport($matches, []));

        $this->assertSame([90, 84], [$assessment->score->rawScore, $assessment->score->score]);
        $this->assertSame(['single_column', 'keyword:zed', 'keyword:abc'], array_map(fn ($s) => $s->id, $assessment->suggestions));
        $this->assertSame([12, 0, 0], array_map(fn ($s) => $s->impactPoints, $assessment->suggestions), 'the cap keeps keyword gains at 0');
        $this->assertSame(['major', 'minor', 'info'], array_map(fn ($s) => $s->severity, $assessment->suggestions));
    }

    public function test_insufficient_job_description_gives_one_info_suggestion(): void
    {
        $assessment = (new Scorer)->assess($this->results(), new KeywordReport([$this->match('PHP', 'required', null)], []));

        $this->assertSame(['keyword_coverage'], array_map(fn ($s) => $s->id, $assessment->suggestions));
        $this->assertSame(['info', 0, 'insufficient_job_description'], [$assessment->suggestions[0]->severity, $assessment->suggestions[0]->impactPoints, $assessment->suggestions[0]->key]);
        $this->assertSame(100, $assessment->score->score);
    }

    public function test_no_score_means_null_impacts(): void
    {
        $assessment = (new Scorer)->assess($this->results([
            'readable_text' => CheckResult::fail('readable_text', 'too_short', ['words' => 25]),
            'length' => CheckResult::fail('length', 'too_short', ['words' => 25]),
        ]));

        $this->assertSame(['readable_text', 'length'], array_map(fn ($s) => $s->id, $assessment->suggestions), 'blocker before minor');
        $this->assertSame([null, null], array_map(fn ($s) => $s->impactPoints, $assessment->suggestions));
    }
}
