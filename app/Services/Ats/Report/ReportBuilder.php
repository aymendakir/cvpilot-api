<?php

namespace App\Services\Ats\Report;

use App\Services\Ats\Checks\CheckResult;
use App\Services\Ats\Checks\CheckStatus;
use App\Services\Ats\Parsing\Detection;
use App\Services\Ats\Parsing\ParsedDocument;
use App\Services\Ats\Scoring\Assessment;
use App\Services\Ats\Scoring\MessageCatalog;
use App\Services\Ats\Scoring\Suggestion;
use App\Services\Ats\Sections\Sections;
use DateTimeInterface;

/** Assessment + document → the §5.2 report body, with every text in the report locale. */
final class ReportBuilder
{
    public const MAX_TEXT = 100_000;

    /** @var list<string> sides of the column samples of the document being built ("left", "right") */
    private array $columnSides = [];

    /**
     * @param  'file'|'text'  $source
     * @param  'en'|'fr'|'other'  $language  detected CV language
     */
    public function build(
        Assessment $assessment,
        ParsedDocument $document,
        Sections $sections,
        string $language,
        MessageCatalog $catalog,
        string $source,
        ?string $fileName,
        bool $includeText,
        DateTimeInterface $now,
    ): AtsReport {
        $score = $assessment->score;
        $this->columnSides = array_values($document->structure->columns->extra['sample_sides'] ?? []);
        $documentData = [
            'source' => $source,
            'file_name' => $source === 'file' ? $fileName : null,
            'type' => $document->type,
            'size_bytes' => $document->sizeBytes,
            'pages' => $document->pages,
            'word_count' => $document->wordCount,
            'text_extractable' => $document->textExtractable,
            'structure_inspected' => $document->structure->inspected,
        ];
        if ($includeText) {
            $documentData['text'] = mb_substr($document->text, 0, self::MAX_TEXT);
        }

        return new AtsReport($assessment, [
            'version' => config('ats.version'),
            'mode' => $assessment->keywords !== null ? 'job_match' : 'document',
            'score_status' => $score->status,
            'score' => $score->score,
            'raw_score' => $score->rawScore,
            'grade' => $score->grade,
            'summary' => $catalog->summary($score, $assessment->suggestions, $document->wordCount),
            'locale' => $catalog->locale(),
            'language' => ['detected' => $language, 'supported' => in_array($language, ['en', 'fr'], true)],
            'document' => $documentData,
            'categories' => $this->categories($assessment, $catalog),
            'keywords' => $assessment->keywords?->toArray(),
            'sections' => $this->sections($assessment->results, $sections),
            'formatting' => $this->formatting($document, $catalog),
            'caps' => array_map(fn (array $cap) => $cap + ['reason' => $catalog->capReason($cap['id'])], $score->caps),
            'suggestions' => array_map(fn (Suggestion $s) => $this->suggestion($s, $catalog), $assessment->suggestions),
            'limitations' => $this->limitations($document, $score->status, $language, $catalog),
            'generated_at' => $now->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function categories(Assessment $assessment, MessageCatalog $catalog): array
    {
        $out = [];
        foreach ($assessment->score->categories as $category) {
            $checks = [];
            foreach ($assessment->score->checks as $id => $points) {
                if (config("ats.checks.{$id}.category") !== $category['id']) {
                    continue;
                }
                $result = $assessment->results[$id] ?? $this->keywordResult($assessment);
                $checks[] = [
                    'id' => $id,
                    'title' => $catalog->checkTitle($id),
                    'severity' => config("ats.checks.{$id}.severity"),
                    'status' => $points['status']->value,
                    'earned' => $points['earned'],
                    'max' => $points['max'],
                    'applicable' => $points['applicable'],
                    // §5.2 needs a value; a check that could not be judged has low confidence.
                    'confidence' => $result->confidence?->value ?? 'low',
                    'finding' => $catalog->finding($id, $result->findingKey, $result->params),
                    'action' => $points['status'] === CheckStatus::Fail ? $catalog->action($id, $result->findingKey, $result->params) : null,
                    'evidence' => $this->evidence($id, $result->evidence, $catalog),
                ];
            }
            $out[] = ['id' => $category['id'], 'title' => $catalog->categoryTitle($category['id']), 'earned' => $category['earned'], 'max' => $category['max'], 'checks' => $checks];
        }

        return $out;
    }

    /** keyword_coverage as a check result, for its finding text. */
    private function keywordResult(Assessment $assessment): CheckResult
    {
        $keywords = $assessment->keywords;
        if ($keywords === null || $keywords->coverage() === null) {
            return CheckResult::unverified('keyword_coverage', 'insufficient_job_description');
        }
        $matched = count(array_filter($keywords->matches, fn ($m) => $m->matched()));
        $params = ['matched' => $matched, 'total' => count($keywords->matches), 'percent' => round(100 * $keywords->coverage(), 1)];

        return $keywords->coverage() >= 1.0
            ? CheckResult::pass('keyword_coverage', 'complete', $params)
            : CheckResult::fail('keyword_coverage', 'partial', $params);
    }

    /** @param array<string, CheckResult> $results */
    private function sections(array $results, Sections $sections): array
    {
        $found = fn (string $kind) => [
            'found' => $sections->found($kind),
            'heading' => $sections->headings[$kind]['text'] ?? null,
            'line' => $sections->headings[$kind]['line'] ?? null,
        ];

        return [
            'contact' => [
                'email' => $results['email']->status === CheckStatus::Pass ? ($results['email']->params['email'] ?? null) : null,
                'phone' => $results['phone']->status === CheckStatus::Pass ? ($results['phone']->params['phone'] ?? null) : null,
            ],
            'experience' => $found('experience'),
            'education' => $found('education'),
            'skills' => $found('skills'),
        ];
    }

    private function formatting(ParsedDocument $document, MessageCatalog $catalog): array
    {
        $s = $document->structure;
        $images = $s->images->extra;

        return [
            'columns' => $this->detection($s->columns, 'columns', $document, $catalog),
            'tables' => $this->detection($s->tables, 'tables', $document, $catalog),
            'images' => $this->detection($s->images, 'images', $document, $catalog) + [
                'count' => (int) ($images['count'] ?? 0),
                'largest_area_pct' => isset($images['largest_area_pct']) ? (float) $images['largest_area_pct'] : null,
            ],
            'text_boxes' => $this->detection($s->textBoxes, 'text_boxes', $document, $catalog),
            'header_footer' => $this->detection($s->headerFooter, 'header_footer', $document, $catalog) + [
                'contact_only_there' => (bool) ($s->headerFooter->extra['contact_only_there'] ?? false),
            ],
            'glyph_issues' => $this->detection($s->glyphIssues, 'glyph_issues', $document, $catalog),
        ];
    }

    /** @return array{detected: ?bool, confidence: ?string, note: ?string} */
    private function detection(Detection $d, string $signal, ParsedDocument $document, MessageCatalog $catalog): array
    {
        $extra = $d->extra;
        $pageList = isset($extra['pages']) && is_array($extra['pages']) ? array_values($extra['pages']) : [];
        $pages = $pageList === [] ? null : $this->listOf($pageList, $catalog);
        $note = match (true) {
            $d->detected === null && $document->type === 'text' => $catalog->formattingNote('common', 'not_inspected'),
            $d->detected === null => $catalog->formattingNote('common', 'not_applicable_pdf'),
            $d->detected === false && $signal === 'header_footer' && $document->type === 'pdf' && $document->pages === 1 => $catalog->formattingNote('header_footer', 'single_page'),
            $d->detected === false => null,
            $signal === 'columns' => $pages !== null ? $catalog->formattingNote('columns', 'found_pages', ['pages' => $pages, 'count' => count($pageList)]) : $catalog->formattingNote('columns', 'found'),
            $signal === 'tables' && $d->confidence?->isLow() && $pages !== null => $catalog->formattingNote('tables', 'possible', ['pages' => $pages, 'count' => count($pageList)]),
            $signal === 'tables', $signal === 'text_boxes' => $catalog->formattingNote($signal, 'found', ['count' => (int) ($extra['count'] ?? 0)]),
            $signal === 'images' => isset($extra['largest_area_pct'])
                ? $catalog->formattingNote('images', 'found_area', ['count' => (int) ($extra['count'] ?? 0), 'largest_area_pct' => (float) $extra['largest_area_pct']])
                : $catalog->formattingNote('images', 'found', ['count' => (int) ($extra['count'] ?? 0)]),
            $signal === 'header_footer' => $catalog->formattingNote('header_footer', ($extra['contact_only_there'] ?? false) ? 'contact_only' : 'found'),
            default => $catalog->formattingNote('glyph_issues', 'found'),
        };

        return ['detected' => $d->detected, 'confidence' => $d->confidence?->value, 'note' => $note];
    }

    private function suggestion(Suggestion $s, MessageCatalog $catalog): array
    {
        $text = $catalog->suggestion($s);

        return [
            'id' => $s->id,
            'rank' => $s->rank,
            'severity' => $s->severity,
            'category' => $s->category,
            'check_id' => $s->checkId,
            'title' => $text['title'],
            'detail' => $text['detail'],
            'action' => $text['action'],
            'impact_points' => $s->impactPoints,
            'evidence' => $this->evidence($s->checkId, $s->evidence, $catalog),
        ];
    }

    /** @return list<string> */
    private function limitations(ParsedDocument $document, string $status, string $language, MessageCatalog $catalog): array
    {
        $keys = ['estimate'];
        if ($document->type === 'pdf') {
            $keys[] = 'pdf_heuristics';
        }
        if ($status === 'unreadable') {
            $keys[] = 'no_ocr';
        }
        if ($document->type === 'text') {
            $keys[] = 'pasted_text';
        }
        if ($language === 'other') {
            $keys[] = 'other_language';
        }

        return array_map(fn ($k) => $catalog->limitation($k), $keys);
    }

    /**
     * Column samples are labelled in the report locale ("Left column: …", « Colonne de gauche : … »);
     * every other evidence line is the CV text as found.
     *
     * @param  list<string>  $evidence
     * @return list<string>
     */
    private function evidence(string $checkId, array $evidence, MessageCatalog $catalog): array
    {
        if ($checkId !== 'single_column' || count($evidence) !== count($this->columnSides)) {
            return $evidence;
        }

        return array_map(function (string $text, string $side) use ($catalog) {
            $line = $catalog->formattingNote('columns', $side, ['text' => $text]);

            return mb_strlen($line) <= 200 ? $line : rtrim(mb_substr($line, 0, 199)).'…'; // §5.2: ≤ 200 chars
        }, $evidence, $this->columnSides);
    }

    /** "1", "1 and 2", "1, 2 and 3" in the report locale. @param list<int|string> $items */
    private function listOf(array $items, MessageCatalog $catalog): string
    {
        $last = (string) array_pop($items);

        return $items === [] ? $last : implode(', ', $items).' '.$catalog->formattingNote('common', 'and').' '.$last;
    }
}
