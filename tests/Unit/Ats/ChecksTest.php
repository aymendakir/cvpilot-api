<?php

namespace Tests\Unit\Ats;

use App\Services\Ats\Checks\ActionVerbs;
use App\Services\Ats\Checks\CheckResult;
use App\Services\Ats\Checks\CheckRunner;
use App\Services\Ats\Checks\Patterns;
use App\Services\Ats\Parsing\Confidence;
use App\Services\Ats\Parsing\Detection;
use App\Services\Ats\Parsing\DocumentReader;
use App\Services\Ats\Parsing\ParsedDocument;
use App\Services\Ats\Parsing\Structure;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The 17 document checks, each with pass, fail and (where it applies) unverified cases (SPEC-ats.md §6). */
class ChecksTest extends TestCase
{
    /** A complete, healthy CV body; tests change one thing at a time. */
    private const GOOD = <<<'CV'
Jane Doe
jane.doe@example.com · +33 6 12 34 56 78
Profile
Backend developer building reliable web applications for product teams, with a focus on clear code and careful testing.
Work Experience
Backend Developer, Acme, Paris
Mar 2021 – Present
• Built a billing API used by 40 partner companies across France and Belgium every day.
• Reduced invoice errors by 30% by adding validation and automated reconciliation reports.
• Introduced code review guidelines that the whole team of six developers now follows.
Junior Developer, Beta, Lyon
Sep 2018 – Feb 2021
• Developed internal dashboards for the sales team and the customer support agents.
• Maintained the public website and fixed reported bugs within the agreed deadlines.
Education
Master in Computer Science, Université de Lyon
Skills
PHP, Laravel, MySQL, Docker
CV;

    /** @return array<string, CheckResult> */
    private function check(string $text): array
    {
        $text .= "\n".str_repeat('Additional detail about projects, tools and responsibilities in previous roles. ', 20);

        return (new CheckRunner)->run((new DocumentReader)->parseText($text));
    }

    private function statusOf(string $text, string $id): string
    {
        return $this->check($text)[$id]->status->value;
    }

    public function test_the_good_cv_passes_every_text_check_and_layout_checks_are_unverified_for_pasted_text(): void
    {
        foreach ($this->check(self::GOOD) as $id => $result) {
            $expected = in_array($id, ['single_column', 'layout_tables', 'images', 'text_boxes_headers', 'file_supported'], true) ? 'unverified' : 'pass';
            $this->assertSame($expected, $result->status->value, "{$id}: {$result->findingKey}");
        }
    }

    // --- A format ---------------------------------------------------------------------------

    public function test_readable_text(): void
    {
        $runner = new CheckRunner;
        $this->assertSame('too_short', $runner->run((new DocumentReader)->parseText('Jane Doe, developer.'))['readable_text']->findingKey);
        $garbled = $runner->run((new DocumentReader)->parseText(str_repeat("Developer \u{FFFD}\u{FFFD} ", 40)))['readable_text'];
        $this->assertSame(['fail', 'garbled'], [$garbled->status->value, $garbled->findingKey]);
    }

    public function test_without_text_only_readable_text_is_judged(): void
    {
        $scan = new ParsedDocument('pdf', '', [], 1, 0, false, new Structure(true, Detection::absent(), Detection::absent(), Detection::found(Confidence::Medium, '1 image', [], ['count' => 1, 'largest_area_pct' => 100.0]), Detection::notInspected(), Detection::absent(), Detection::absent()), 1000);
        $results = (new CheckRunner)->run($scan);

        $this->assertSame('fail', $results['readable_text']->status->value);
        $this->assertSame('no_text', $results['readable_text']->findingKey);
        foreach (array_slice($results, 1) as $id => $r) {
            $this->assertSame('unverified', $r->status->value, $id);
        }
    }

    /** A DOCX-like document with one structure signal changed. */
    private function structured(array $signals, string $type = 'docx', int $bytes = 50000): array
    {
        $base = ['columns' => Detection::absent(), 'tables' => Detection::absent(), 'images' => Detection::absent(Confidence::High, ['count' => 0, 'largest_area_pct' => null]), 'textBoxes' => Detection::absent(), 'headerFooter' => Detection::absent(Confidence::High, ['contact_only_there' => false]), 'glyphIssues' => Detection::absent()];
        $s = $signals + $base;
        $doc = (new DocumentReader)->parseText(self::GOOD);
        $doc = new ParsedDocument($type, $doc->text, $doc->lines, 1, $doc->wordCount, true, new Structure(true, $s['columns'], $s['tables'], $s['images'], $s['textBoxes'], $s['headerFooter'], $s['glyphIssues']), $bytes);

        return (new CheckRunner)->run($doc);
    }

    /** @return array<string, array{0: string, 1: array, 2: string}> */
    public static function structureCases(): array
    {
        return [
            'columns medium → fail' => ['single_column', ['columns' => Detection::found(Confidence::Medium, '2 columns')], 'fail'],
            'columns low → unverified' => ['single_column', ['columns' => Detection::found(Confidence::Low, 'near miss')], 'unverified'],
            'table high → fail' => ['layout_tables', ['tables' => Detection::found(Confidence::High, '1 table')], 'fail'],
            'PDF table (low) → unverified' => ['layout_tables', ['tables' => Detection::found(Confidence::Low, 'aligned cells')], 'unverified'],
            'small photo → pass' => ['images', ['images' => Detection::found(Confidence::High, '1 image', [], ['count' => 1, 'largest_area_pct' => 2.6])], 'pass'],
            'image ≥ 15 % → fail' => ['images', ['images' => Detection::found(Confidence::High, '1 image', [], ['count' => 1, 'largest_area_pct' => 15.0])], 'fail'],
            'three images → fail' => ['images', ['images' => Detection::found(Confidence::Medium, '3 images', [], ['count' => 3, 'largest_area_pct' => 1.0])], 'fail'],
            'two images, area unknown → pass' => ['images', ['images' => Detection::found(Confidence::Medium, '2 images', [], ['count' => 2, 'largest_area_pct' => null])], 'pass'],
            'text box → fail' => ['text_boxes_headers', ['textBoxes' => Detection::found(Confidence::High, '1 box', ['Skills'], ['count' => 1])], 'fail'],
            'contact only in header → fail' => ['text_boxes_headers', ['headerFooter' => Detection::found(Confidence::High, 'header', [], ['contact_only_there' => true])], 'fail'],
            'header without contact → pass' => ['text_boxes_headers', ['headerFooter' => Detection::found(Confidence::High, 'header', [], ['contact_only_there' => false])], 'pass'],
            'PDF: no text boxes to inspect → judged on header' => ['text_boxes_headers', ['textBoxes' => Detection::notInspected('n/a')], 'pass'],
            'icon glyphs → fail' => ['clean_characters', ['glyphIssues' => Detection::found(Confidence::High, '2 icons', [], ['private_use' => 2])], 'fail'],
        ];
    }

    #[DataProvider('structureCases')]
    public function test_structure_checks(string $id, array $signals, string $status): void
    {
        $this->assertSame($status, $this->structured($signals)[$id]->status->value);
    }

    public function test_file_supported(): void
    {
        $this->assertSame('pass', $this->structured([], 'pdf', 5 * 1024 * 1024)['file_supported']->status->value);
        $tooBig = $this->structured([], 'docx', 5 * 1024 * 1024 + 1)['file_supported'];
        $this->assertSame(['fail', 'too_large'], [$tooBig->status->value, $tooBig->findingKey]);
    }

    // --- B sections -------------------------------------------------------------------------

    public function test_email_and_phone(): void
    {
        $this->assertSame('fail', $this->statusOf(str_replace('jane.doe@example.com · ', '', self::GOOD), 'email'));
        $this->assertSame('fail', $this->statusOf(str_replace(' · +33 6 12 34 56 78', '', self::GOOD), 'phone'));
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function phones(): array
    {
        return [
            'international' => ['Tel: +212 600 123 456', '+212 600 123 456'],
            'French local' => ['06 12 34 56 78', '06 12 34 56 78'],
            'with brackets' => ['(+44) 20 7946 0958', '(+44) 20 7946 0958'],
            'year range is not a phone' => ['2016 - 2019', null],
            'date is not a phone' => ['12/05/2020', null],
            'too short' => ['Room 1234', null],
        ];
    }

    #[DataProvider('phones')]
    public function test_phone_pattern(string $text, ?string $phone): void
    {
        $this->assertSame($phone, Patterns::phone($text));
    }

    public function test_section_checks_need_a_heading_and_an_entry(): void
    {
        $this->assertSame('fail', $this->statusOf(str_replace("Education\nMaster in Computer Science, Université de Lyon\n", '', self::GOOD), 'education_section'));
        // A heading with nothing under it (the CV ends on it) is "empty", not "found".
        $empty = (new CheckRunner)->run((new DocumentReader)->parseText(str_replace("\nSkills\nPHP, Laravel, MySQL, Docker", "\nLanguages\nFrench\nSkills", self::GOOD)))['skills_section'];
        $this->assertSame(['fail', 'empty'], [$empty->status->value, $empty->findingKey]);
    }

    public function test_dates_need_two_ranges_in_one_style(): void
    {
        $this->assertSame('pass', $this->statusOf(self::GOOD, 'dates'));
        $mixed = $this->check(str_replace('Sep 2018 – Feb 2021', '09/2018 – 02/2021', self::GOOD))['dates'];
        $this->assertSame(['fail', 'mixed_styles'], [$mixed->status->value, $mixed->findingKey]);
        $one = $this->check(str_replace('Sep 2018 – Feb 2021', 'Since autumn', self::GOOD))['dates'];
        $this->assertSame(['fail', 'too_few'], [$one->status->value, $one->findingKey]);
        $this->assertSame('pass', $this->statusOf(str_replace(['Mar 2021 – Present', 'Sep 2018 – Feb 2021'], ['2021 – en cours', '2018 – 2021'], self::GOOD), 'dates'));
    }

    // --- C content --------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: bool}> */
    public static function actionStarts(): array
    {
        return [
            'built' => ['Built a billing API.', true],
            'develop (present tense)' => ['Develop internal tools.', true],
            'British spelling' => ['Organised weekly demos.', true],
            'set up (phrase)' => ['Set up continuous integration.', true],
            'développé' => ['Développé une API Symfony.', true],
            'développée' => ['Développée en équipe.', true],
            'mis en place' => ['Mis en place les tests unitaires.', true],
            'French action noun' => ['Développement d’une application mobile.', true],
            'Mise en place' => ['Mise en place de la CI.', true],
            'responsible for' => ['Responsible for the billing system.', false],
            'tasked with' => ['Tasked with maintenance.', false],
            'chargé de' => ['Chargé de la maintenance.', false],
            'a technology' => ['PHP and Laravel applications.', false],
        ];
    }

    #[DataProvider('actionStarts')]
    public function test_action_starts(string $bullet, bool $action): void
    {
        $this->assertSame($action, (new ActionVerbs)->startsWithAction($bullet));
    }

    public function test_action_verbs_need_sixty_percent(): void
    {
        $weak = str_replace(['• Built', '• Reduced', '• Introduced'], ['• Responsible for building', '• In charge of reducing', '• Tasked with introducing'], self::GOOD);
        $result = $this->check($weak)['action_verbs'];

        $this->assertSame(['fail', 'weak_start'], [$result->status->value, $result->findingKey]);
        $this->assertSame(['count' => 2, 'total' => 5], $result->params);
    }

    public function test_quantified_results_ignore_years(): void
    {
        $this->assertTrue(Patterns::quantified('Saved 6 hours a week'));
        $this->assertTrue(Patterns::quantified('Cut costs by 15%'));
        $this->assertTrue(Patterns::quantified('Sold for €2k'));
        $this->assertFalse(Patterns::quantified('Joined the team in 2019'));
        $none = str_replace(['used by 40 partner', 'by 30% by'], ['used by partner', 'by'], self::GOOD);
        $this->assertSame('fail', $this->statusOf($none, 'quantified_results'));
    }

    public function test_length_bounds(): void
    {
        $this->assertSame('too_short', (new CheckRunner)->run((new DocumentReader)->parseText(self::GOOD))['length']->findingKey);
        $this->assertSame('too_long', $this->check(self::GOOD.str_repeat(' word', 1000))['length']->findingKey);
    }

    public function test_duplicate_bullets_are_found_ignoring_case_and_punctuation(): void
    {
        $dup = str_replace('• Maintained the public website and fixed reported bugs within the agreed deadlines.', "• Maintained the public website and fixed reported bugs within the agreed deadlines.\n• maintained the public website, and fixed reported bugs within the agreed deadlines", self::GOOD);
        $result = $this->check($dup)['no_duplicates'];

        $this->assertSame(['fail', 'duplicates'], [$result->status->value, $result->findingKey]);
        $this->assertCount(1, $result->evidence);
    }
}
