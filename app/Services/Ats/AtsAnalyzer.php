<?php

namespace App\Services\Ats;

use App\Services\Ats\Checks\CheckRunner;
use App\Services\Ats\Keywords\KeywordAnalyzer;
use App\Services\Ats\Parsing\DocumentReader;
use App\Services\Ats\Parsing\ParsedDocument;
use App\Services\Ats\Parsing\UnreadableDocument;
use App\Services\Ats\Report\AtsReport;
use App\Services\Ats\Report\ReportBuilder;
use App\Services\Ats\Scoring\MessageCatalog;
use App\Services\Ats\Scoring\Scorer;
use Closure;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The ATS checker (SPEC-ats.md §4): parse → checks → keywords → score → suggestions → report.
 * Deterministic and offline; the clock only sets `generated_at` (R7). S4 puts it behind the route.
 */
final class AtsAnalyzer
{
    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    public function __construct(
        private readonly DocumentReader $reader = new DocumentReader,
        private readonly CheckRunner $runner = new CheckRunner,
        private readonly KeywordAnalyzer $keywords = new KeywordAnalyzer,
        private readonly Scorer $scorer = new Scorer,
        private readonly ReportBuilder $builder = new ReportBuilder,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? fn () => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** @throws UnreadableDocument password-protected, corrupt, unsupported or timed out (→ 422 in S4) */
    public function analyzeFile(string $path, string $fileName, ?string $jobDescription = null, ?string $locale = null, bool $includeText = false): AtsReport
    {
        $document = $this->reader->parseFile($path, $fileName);
        if ($document->type === 'text') {
            // §5.1: an uploaded file must be a PDF or DOCX; plain text is sent as `cv_text`.
            throw new UnreadableDocument(UnreadableDocument::UNSUPPORTED_TYPE);
        }

        return $this->analyze($document, 'file', $fileName, $jobDescription, $locale, $includeText);
    }

    public function analyzeText(string $cvText, ?string $jobDescription = null, ?string $locale = null, bool $includeText = false): AtsReport
    {
        return $this->analyze($this->reader->parseText($cvText), 'text', null, $jobDescription, $locale, $includeText);
    }

    private function analyze(ParsedDocument $document, string $source, ?string $fileName, ?string $jobDescription, ?string $locale, bool $includeText): AtsReport
    {
        $context = $this->runner->context($document);
        $keywords = $jobDescription !== null && trim($jobDescription) !== ''
            ? $this->keywords->analyze($jobDescription, $context->sections, $context->language)
            : null;
        $assessment = $this->scorer->assess($this->runner->run($document, $context), $keywords);

        return $this->builder->build(
            $assessment, $document, $context->sections, $context->language,
            new MessageCatalog(self::locale($locale, $context->language)),
            $source, $fileName, $includeText, ($this->clock)(),
        );
    }

    /** §5.1: the requested locale, else the CV language when en/fr, else en. */
    public static function locale(?string $requested, string $detected): string
    {
        foreach ([$requested, $detected] as $candidate) {
            if (in_array($candidate, MessageCatalog::LOCALES, true)) {
                return $candidate;
            }
        }

        return 'en';
    }
}
