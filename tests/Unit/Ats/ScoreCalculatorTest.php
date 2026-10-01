<?php

namespace Tests\Unit\Ats;

use App\Services\Ats\Checks\CheckResult;
use App\Services\Ats\Checks\CheckStatus;
use App\Services\Ats\Scoring\ScoreCalculator;
use App\Services\Ats\Scoring\Scorer;
use Tests\TestCase;

/** Points, caps, grade and status rules (SPEC-ats.md §6 R1, R3, R4, R8; S3 decisions 23–24). */
class ScoreCalculatorTest extends TestCase
{
    /** @return array<string, CheckStatus> every document check passing, with overrides */
    private function statuses(array $overrides = []): array
    {
        $all = array_fill_keys(array_keys(array_diff_key(config('ats.checks'), ['keyword_coverage' => 1])), CheckStatus::Pass);

        return array_merge($all, $overrides);
    }

    public function test_round_half_up(): void
    {
        $this->assertSame(23, ScoreCalculator::roundHalfUp(22.5));
        $this->assertSame(22, ScoreCalculator::roundHalfUp(22.4999));
        $this->assertSame(21, ScoreCalculator::roundHalfUp(30 * 0.708));
        $this->assertSame(3, ScoreCalculator::roundHalfUp(2.5));
    }

    public function test_all_pass_is_100_and_unverified_checks_leave_both_sums(): void
    {
        $calc = new ScoreCalculator;
        $this->assertSame(100, $calc->calculate($this->statuses(), false, null)->score);

        $text = $calc->calculate($this->statuses(array_fill_keys(['single_column', 'layout_tables', 'images', 'text_boxes_headers', 'file_supported'], CheckStatus::Unverified)), false, null);
        $this->assertSame(100, $text->score);
        $this->assertSame(['format', 6 + 2, 6 + 2], [$text->categories[0]['id'], $text->categories[0]['earned'], $text->categories[0]['max']]);
        $this->assertFalse($text->checks['images']['applicable']);
    }

    public function test_caps_are_listed_when_triggered_and_applied_when_binding(): void
    {
        $calc = new ScoreCalculator;
        $columns = $calc->calculate($this->statuses(['single_column' => CheckStatus::Fail]), false, null);
        $this->assertSame([91, 84], [$columns->rawScore, $columns->score]); // 64/70
        $this->assertSame([['id' => 'major_format_issue', 'limit' => 84, 'applied' => true]], $columns->caps);

        $weak = $calc->calculate($this->statuses(['email' => CheckStatus::Fail, 'experience_section' => CheckStatus::Fail, 'action_verbs' => CheckStatus::Fail,
            'quantified_results' => CheckStatus::Fail, 'length' => CheckStatus::Fail, 'dates' => CheckStatus::Fail, 'skills_section' => CheckStatus::Fail]), false, null);
        $this->assertSame($weak->rawScore, $weak->score);
        $this->assertSame([['id' => 'no_email', 'limit' => 79, 'applied' => false], ['id' => 'no_experience', 'limit' => 74, 'applied' => false]], $weak->caps);

        $unverified = $calc->calculate($this->statuses(['single_column' => CheckStatus::Unverified]), false, null);
        $this->assertSame([], $unverified->caps, 'unverified never triggers a cap');
    }

    public function test_lowest_binding_cap_wins(): void
    {
        $result = (new ScoreCalculator)->calculate($this->statuses(['experience_section' => CheckStatus::Fail, 'images' => CheckStatus::Fail]), false, null);
        $this->assertSame(74, $result->score);
        $this->assertCount(2, $result->caps);
    }

    public function test_keyword_coverage_points_status_and_insufficient_job_description(): void
    {
        $calc = new ScoreCalculator;
        $full = $calc->calculate($this->statuses(), true, 1.0);
        $this->assertSame([CheckStatus::Pass, 30], [$full->checks['keyword_coverage']['status'], $full->checks['keyword_coverage']['earned']]);
        $this->assertSame('keywords', $full->categories[3]['id']);

        $half = $calc->calculate($this->statuses(), true, 0.75);
        $this->assertSame([CheckStatus::Fail, 23], [$half->checks['keyword_coverage']['status'], $half->checks['keyword_coverage']['earned']]);
        $this->assertSame(93, $half->score); // 93/100

        $short = $calc->calculate($this->statuses(), true, null);
        $this->assertSame(CheckStatus::Unverified, $short->checks['keyword_coverage']['status']);
        $this->assertSame(100, $short->score, 'scored like document mode');
        $this->assertSame(['id' => 'keywords', 'earned' => 0, 'max' => 0], $short->categories[3], 'the category stays, with nothing applicable');
    }

    public function test_grades(): void
    {
        $this->assertSame(['strong', 'good', 'good', 'needs_work', 'needs_work', 'poor'], array_map(
            fn ($s) => ScoreCalculator::grade($s), [85, 84, 70, 69, 50, 49],
        ));
    }

    public function test_status_from_readable_text(): void
    {
        $this->assertSame('scored', Scorer::status(CheckResult::pass('readable_text', 'ok')));
        $this->assertSame('insufficient_text', Scorer::status(CheckResult::fail('readable_text', 'too_short', ['words' => 20])));
        $this->assertSame('unreadable', Scorer::status(CheckResult::fail('readable_text', 'no_text')));
        $this->assertSame('unreadable', Scorer::status(CheckResult::fail('readable_text', 'garbled', ['percent' => 4.0])));
    }

    public function test_unreadable_and_insufficient_text_have_no_score(): void
    {
        $results = [];
        foreach (array_keys($this->statuses()) as $id) {
            $results[$id] = CheckResult::pass($id, 'ok');
        }

        $garbled = (new Scorer)->assess(['readable_text' => CheckResult::fail('readable_text', 'garbled', ['percent' => 4.0])] + $results);
        $this->assertSame(['unreadable', null, null, null, []], [$garbled->score->status, $garbled->score->score, $garbled->score->rawScore, $garbled->score->grade, $garbled->score->caps]);
        $this->assertSame(CheckStatus::Unverified, $garbled->results['email']->status, 'R4: other checks unverified');

        $short = (new Scorer)->assess(['readable_text' => CheckResult::fail('readable_text', 'too_short', ['words' => 20])] + $results);
        $this->assertSame(['insufficient_text', null], [$short->score->status, $short->score->score]);
        $this->assertSame(CheckStatus::Pass, $short->results['email']->status, 'checks still run and are shown');
    }
}
