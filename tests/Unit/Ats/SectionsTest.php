<?php

namespace Tests\Unit\Ats;

use App\Services\Ats\Sections\DateRanges;
use App\Services\Ats\Sections\SectionDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Section detection, bullets and date ranges (SPEC-ats.md §6 B/C, §8.4). */
class SectionsTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string}> */
    public static function headings(): array
    {
        return [
            // §8.4 table
            'Work Experience' => ['Work Experience', 'experience'],
            'Expérience professionnelle' => ['Expérience professionnelle', 'experience'],
            'Projects' => ['Projects', 'experience'],
            'Formations' => ['Formations', 'education'],
            'Compétences techniques' => ['Compétences techniques', 'skills'],
            'S K I L L S' => ['S K I L L S', 'skills'],
            // legacy AtsDocumentReview cases and common variants
            'FORMATIONS' => ['FORMATIONS', 'education'],
            'COMPÉTENCES' => ['COMPÉTENCES', 'skills'],
            'EXPÉRIENCE PROFESSIONNELLE' => ['EXPÉRIENCE PROFESSIONNELLE', 'experience'],
            'letter-spaced two words' => ['E X P É R I E N C E  P R O F E S S I O N N E L L E', 'experience'],
            'Stages et expériences' => ['Stages et expériences', 'experience'],
            'Projets techniques' => ['Projets techniques', 'experience'],
            'Professional Experience:' => ['Professional Experience:', 'experience'],
            'numbered heading' => ['2. Education', 'education'],
            'Technical Skills' => ['Technical Skills', 'skills'],
            'Skills & Tools' => ['Skills & Tools', 'skills'],
            'Profile is another section' => ['Profile', 'other'],
            'Langues' => ['Langues', 'other'],
            "Centres d'intérêt" => ["Centres d\u{2019}intérêt", 'other'],
            // not headings
            'prose mentioning experience' => ['Five years of experience in web development.', null],
            'long line' => ['Experience building APIs for clients across Morocco and France', null],
            'a skill line' => ['PHP, Laravel, MySQL', null],
            'a role line' => ['Backend Developer, Atlas Commerce, Rabat', null],
        ];
    }

    #[DataProvider('headings')]
    public function test_heading_detection(string $line, ?string $kind): void
    {
        $this->assertSame($kind, (new SectionDetector)->headingKind($line));
    }

    public function test_lines_belong_to_the_section_above_them_and_header_comes_first(): void
    {
        $s = (new SectionDetector)->detect(['Jane Doe', 'jane@example.com', 'Experience', 'Developer, Acme', '• Built things.', 'FORMATIONS', 'Licence', 'Skills', 'PHP']);

        $this->assertSame(['header', 'header', 'experience', 'experience', 'experience', 'education', 'education', 'skills', 'skills'], $s->sectionOf);
        $this->assertSame(['text' => 'FORMATIONS', 'line' => 6], $s->headings['education']);
        $this->assertSame([3 => 'Developer, Acme', 4 => '• Built things.'], $s->lines('experience'));
    }

    public function test_legacy_canva_french_case(): void
    {
        $lines = explode("\n", "D É V E L O P P E U R\ncontact@example.com\nEXPÉRIENCE PROFESSIONNELLE\n2025 Développeur dans une entreprise\n- Développement d'applications pour plusieurs clients et mise en production.\n- Création d'interfaces utilisateur avec React et collaboration directe avec les clients.\nFORMATIONS\nDiplôme de développement informatique en 2024.\nCOMPÉTENCES\nReact PHP Laravel SQL et JavaScript.");
        $s = (new SectionDetector)->detect($lines);

        $this->assertTrue($s->found('experience'));
        $this->assertTrue($s->found('education'));
        $this->assertTrue($s->found('skills'));
        $this->assertSame('header', $s->sectionOf[0], 'a letter-spaced job title is not a section heading');
        $this->assertCount(2, $s->bullets());
    }

    public function test_bullets_join_wrapped_pdf_lines_and_stop_at_the_next_entry(): void
    {
        $s = (new SectionDetector)->detect([
            'Work Experience', 'Developer, Acme', 'Mar 2022 – Present',
            '• Built a RESTful API that serves the mobile', 'app and the partner portal.',
            '• Reduced response time by 40%.',
            'Junior Developer, Beta', '- Maintained client websites.',
        ]);

        $this->assertSame([
            'Built a RESTful API that serves the mobile app and the partner portal.',
            'Reduced response time by 40%.',
            'Maintained client websites.',
        ], $s->bullets());
    }

    public function test_without_markers_long_lines_are_bullets_and_date_lines_are_not(): void
    {
        $s = (new SectionDetector)->detect([
            'Experience', 'Developer at Acme', 'Mar 2022 - Present',
            'Built an internal tool used by the support team every day',
            'Reduced the monthly hosting bill by moving to a smaller server',
        ]);

        $this->assertCount(2, $s->bullets());
    }

    /** @return array<string, array{0: string, 1: list<array{0: string, 1: ?string}>}> */
    public static function dates(): array
    {
        return [
            'month, open end' => ['Mar 2022 – Present', [['month', null]]],
            'month range' => ['Jun 2019 – Feb 2022', [['month', 'month']]],
            'French months, open end' => ['mars 2022 – aujourd’hui', [['month', null]]],
            'French full months' => ['septembre 2017 – mai 2019', [['month', 'month']]],
            'numeric' => ['03/2021 - 11/2023', [['numeric', 'numeric']]],
            'years' => ['2016 – 2019', [['year', 'year']]],
            'mixed in one range' => ['Mar 2022 - 11/2023', [['month', 'numeric']]],
            'en cours' => ['2023 - en cours', [['year', null]]],
            'to' => ['January 2020 to March 2021', [['month', 'month']]],
            'no range' => ['Graduated in 2017', []],
            'a phone is not a range' => ['+212 600 123 456', []],
        ];
    }

    #[DataProvider('dates')]
    public function test_date_ranges(string $line, array $expected): void
    {
        $this->assertSame($expected, DateRanges::find($line));
    }
}
