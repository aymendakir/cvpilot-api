<?php

namespace App\Services\Ats\Checks;

use App\Services\Ats\Checks\Content\ActionVerbsCheck;
use App\Services\Ats\Checks\Content\Length;
use App\Services\Ats\Checks\Content\NoDuplicates;
use App\Services\Ats\Checks\Content\QuantifiedResults;
use App\Services\Ats\Checks\Format\CleanCharacters;
use App\Services\Ats\Checks\Format\FileSupported;
use App\Services\Ats\Checks\Format\Images;
use App\Services\Ats\Checks\Format\LayoutTables;
use App\Services\Ats\Checks\Format\ReadableText;
use App\Services\Ats\Checks\Format\SingleColumn;
use App\Services\Ats\Checks\Format\TextBoxesHeaders;
use App\Services\Ats\Checks\Sections\Dates;
use App\Services\Ats\Checks\Sections\EducationSection;
use App\Services\Ats\Checks\Sections\Email;
use App\Services\Ats\Checks\Sections\ExperienceSection;
use App\Services\Ats\Checks\Sections\Phone;
use App\Services\Ats\Checks\Sections\SkillsSection;
use App\Services\Ats\Language\LanguageDetector;
use App\Services\Ats\Parsing\ParsedDocument;
use App\Services\Ats\Sections\SectionDetector;

/**
 * Runs the 17 document checks of SPEC-ats.md §6 (A format, B sections, C content) in table order.
 * The keyword check (D) comes from `ats-keywords`.
 *
 * R4 at check level: when no text can be extracted, readable_text fails and every other check is
 * unverified, since there is nothing to judge.
 */
final class CheckRunner
{
    public function __construct(
        private readonly SectionDetector $sections = new SectionDetector,
        private readonly LanguageDetector $languages = new LanguageDetector,
    ) {}

    /** @return list<Check> */
    public function checks(): array
    {
        return [
            new ReadableText, new SingleColumn, new LayoutTables, new Images, new TextBoxesHeaders, new FileSupported, new CleanCharacters,
            new Email, new Phone, new ExperienceSection, new EducationSection, new SkillsSection, new Dates,
            new ActionVerbsCheck, new QuantifiedResults, new Length, new NoDuplicates,
        ];
    }

    public function context(ParsedDocument $document): CheckContext
    {
        return new CheckContext(
            $document,
            $this->sections->detect(array_map(fn ($line) => $line->text, $document->lines)),
            $this->languages->detect($document->text),
        );
    }

    /** @return array<string, CheckResult> keyed by check id, in §6 order */
    public function run(ParsedDocument $document, ?CheckContext $context = null): array
    {
        $context ??= $this->context($document);
        $results = [];
        foreach ($this->checks() as $check) {
            $results[$check->id()] = ! $document->textExtractable && $check->id() !== 'readable_text'
                ? CheckResult::unverified($check->id(), 'no_text')
                : $check->run($context);
        }

        return $results;
    }
}
