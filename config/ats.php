<?php

/*
 * ATS checker (SPEC-ats.md). Scoring weights and caps join this file in S3; S1 needs the parser
 * settings only.
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
];
