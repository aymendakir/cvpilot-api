<?php

/*
 * ATS checker (SPEC-ats.md). Parser settings (S1) and the scoring model (§6, S3). Changing a point
 * value, cap or band changes scores: update the golden files and the changelog (docs/ats-scoring.md).
 */

return [
    // Scoring-model version reported in every analysis (§5.2).
    'version' => 'ats-2.0',

    // Poppler binaries (poppler-utils). Required: AtsPopplerTest fails without them (docs/DEPLOYMENT.md).
    'poppler' => [
        'pdftotext' => env('ATS_PDFTOTEXT', 'pdftotext'),
        'pdfinfo' => env('ATS_PDFINFO', 'pdfinfo'),
        'pdfimages' => env('ATS_PDFIMAGES', 'pdfimages'),
        'timeout' => (int) env('ATS_POPPLER_TIMEOUT', 10), // seconds per call
        'max_output' => 20 * 1024 * 1024,                    // bytes read from a binary's output
    ],

    // §4.1 structure thresholds (fractions of the page unless stated).
    'structure' => [
        'edge_band' => 0.07,         // header/footer band: top and bottom 7 %
        'column_min_lines' => 8,     // a column needs at least 8 line starts
        'column_high_lines' => 12,   // … 12 for high confidence
        'column_gap' => 0.25,        // columns at least 25 % of the page width apart
        'column_overlap' => 0.5,     // overlapping vertically by at least 50 % (80 % for high)
        'column_high_overlap' => 0.8,
        'table_rows' => 3,           // ≥ 3 consecutive rows …
        'table_cells' => 3,          // … of ≥ 3 aligned cells
        'table_align_pt' => 6.0,
    ],

    // §6 scoring model: category, severity and points of every check, in report order.
    'checks' => [
        'readable_text' => ['category' => 'format', 'severity' => 'blocker', 'points' => 6],
        'single_column' => ['category' => 'format', 'severity' => 'major', 'points' => 6],
        'layout_tables' => ['category' => 'format', 'severity' => 'major', 'points' => 5],
        'images' => ['category' => 'format', 'severity' => 'major', 'points' => 5],
        'text_boxes_headers' => ['category' => 'format', 'severity' => 'minor', 'points' => 3],
        'file_supported' => ['category' => 'format', 'severity' => 'minor', 'points' => 3],
        'clean_characters' => ['category' => 'format', 'severity' => 'minor', 'points' => 2],
        'email' => ['category' => 'sections', 'severity' => 'major', 'points' => 5],
        'phone' => ['category' => 'sections', 'severity' => 'minor', 'points' => 3],
        'experience_section' => ['category' => 'sections', 'severity' => 'major', 'points' => 6],
        'education_section' => ['category' => 'sections', 'severity' => 'minor', 'points' => 4],
        'skills_section' => ['category' => 'sections', 'severity' => 'minor', 'points' => 4],
        'dates' => ['category' => 'sections', 'severity' => 'minor', 'points' => 3],
        'action_verbs' => ['category' => 'content', 'severity' => 'minor', 'points' => 5],
        'quantified_results' => ['category' => 'content', 'severity' => 'minor', 'points' => 5],
        'length' => ['category' => 'content', 'severity' => 'minor', 'points' => 3],
        'no_duplicates' => ['category' => 'content', 'severity' => 'minor', 'points' => 2],
        'keyword_coverage' => ['category' => 'keywords', 'severity' => 'minor', 'points' => 30],
    ],

    // R3 caps, applied after normalization (lowest binding limit wins). A cap is triggered when one of
    // its checks fails (unverified never triggers a cap).
    'caps' => [
        'major_format_issue' => ['limit' => 84, 'checks' => ['single_column', 'layout_tables', 'images']],
        'no_email' => ['limit' => 79, 'checks' => ['email']],
        'no_experience' => ['limit' => 74, 'checks' => ['experience_section']],
    ],

    // R8 grade bands: lowest score of each grade.
    'grades' => ['strong' => 85, 'good' => 70, 'needs_work' => 50, 'poor' => 0],

    // R6 suggestion limits per kind.
    'suggestions' => [
        'keyword_missing_required' => 5,
        'keyword_missing_preferred' => 3,
        'keyword_skills_only' => 3,
    ],
];
