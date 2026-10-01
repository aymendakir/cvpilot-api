<?php

/*
 * ATS report text, English (SPEC-ats.md §5.2). Keys mirror lang/fr/ats.php exactly (AtsMessagesTest).
 * Placeholders come from the checks (S2) and the scorer (S3). "a|b" texts are chosen by :count.
 */

return [
    'categories' => [
        'format' => 'Format',
        'sections' => 'Sections',
        'content' => 'Content',
        'keywords' => 'Job keywords',
    ],

    'checks' => [
        'readable_text' => [
            'title' => 'Readable text',
            'suggestion' => 'Make the text readable',
            'why' => 'If an ATS cannot read the text, nothing else in your CV counts.',
            'fix' => 'make the text readable',
            'findings' => [
                'ok' => 'The text can be read (:words words).',
                'no_text' => 'No text could be read: the file looks like a scan or an image.',
                'garbled' => ':percent % of the characters cannot be read.',
                'too_short' => 'Only :words words could be read; at least 40 are needed to score a CV.',
            ],
            'actions' => [
                'no_text' => 'Export your CV again from your editor as a PDF or DOCX with real text. Do not scan or photograph it.',
                'garbled' => 'Export again using a standard font (Arial, Calibri, Times New Roman), or save as DOCX.',
                'too_short' => 'Write your experience, education and skills as text, not as images.',
            ],
        ],
        'single_column' => [
            'title' => 'Single-column layout',
            'suggestion' => 'Use a single-column layout',
            'why' => 'Many ATS read across columns line by line, which mixes your sidebar into your experience.',
            'fix' => 'use a single-column layout',
            'findings' => [
                'ok' => 'No columns found.',
                'columns' => 'The page uses more than one column of text.',
            ],
            'actions' => [
                'columns' => 'Move the sidebar content (skills, contact details, languages) into sections above or below your experience, in a single column.',
            ],
        ],
        'layout_tables' => [
            'title' => 'No layout tables',
            'suggestion' => 'Remove tables used for layout',
            'why' => 'ATS often skip text inside tables or read it in the wrong order.',
            'fix' => 'remove the layout tables',
            'findings' => [
                'ok' => 'No table containing text.',
                'tables' => 'Some text is placed inside a table.',
            ],
            'actions' => [
                'tables' => 'Replace the table with normal paragraphs and bullet lists.',
            ],
        ],
        'images' => [
            'title' => 'Images',
            'suggestion' => 'Use fewer and smaller images',
            'why' => 'ATS ignore images, and large images push text around or hide it.',
            'fix' => 'use fewer and smaller images',
            'findings' => [
                'none' => 'No images.',
                'small' => '{1} One small image, which is fine.|[2,*] :count small images, which is fine.',
                'too_many' => ':count images (2 at most).',
                'too_large' => 'An image covers about :largest_area_pct % of the page (15 % at most).',
            ],
            'actions' => [
                'too_many' => 'Keep at most one small photo or logo, and remove decorative images and icons.',
                'too_large' => 'Make the photo smaller or remove it, and never put text inside an image.',
            ],
        ],
        'text_boxes_headers' => [
            'title' => 'Text boxes and headers',
            'suggestion' => 'Move content out of text boxes and page headers',
            'why' => 'Many ATS skip text boxes and page headers or footers, so contact details placed there can be lost.',
            'fix' => 'move content out of text boxes and page headers',
            'findings' => [
                'ok' => 'No text in text boxes, and your contact details are in the page body.',
                'text_boxes' => '{0} Text boxes contain text.|{1} One text box contains text.|[2,*] :count text boxes contain text.',
                'contact_in_header' => 'Your contact details appear only in the page header or footer.',
            ],
            'actions' => [
                'text_boxes' => 'Move the text out of the text boxes into the normal page body.',
                'contact_in_header' => 'Put your email address and phone number at the top of the page body, not in the header or footer.',
            ],
        ],
        'file_supported' => [
            'title' => 'File type and size',
            'suggestion' => 'Use a PDF or DOCX file under 5 MB',
            'why' => 'Most ATS accept PDF and DOCX, and often reject very large files.',
            'fix' => 'use a PDF or DOCX file under 5 MB',
            'findings' => [
                'ok' => ':type file, :megabytes MB.',
                'too_large' => 'The file is :megabytes MB (5 MB at most).',
            ],
            'actions' => [
                'too_large' => 'Compress or remove the images, then export the CV again.',
            ],
        ],
        'clean_characters' => [
            'title' => 'Clean characters',
            'suggestion' => 'Replace icons and special characters',
            'why' => 'Icon fonts and letter-spaced headings come out as strange symbols or broken words in ATS.',
            'fix' => 'replace icons and special characters',
            'findings' => [
                'ok' => 'No icons, unreadable characters or letter-spaced headings.',
                'glyphs' => 'Icons, unreadable characters or letter-spaced headings found (icons: :private_use, unreadable: :replacement, letter-spaced headings: :letter_spaced).',
            ],
            'actions' => [
                'glyphs' => 'Write labels as words ("Email", "Phone") instead of icons, and type headings normally ("Skills", not "S K I L L S").',
            ],
        ],
        'email' => [
            'title' => 'Email address',
            'suggestion' => 'Add your email address',
            'why' => 'Recruiters need it to contact you, and many ATS require it.',
            'fix' => 'add your email address',
            'findings' => [
                'found' => 'Found: :email.',
                'missing' => 'No email address found.',
            ],
            'actions' => [
                'missing' => 'Add a professional email address at the top of your CV.',
            ],
        ],
        'phone' => [
            'title' => 'Phone number',
            'suggestion' => 'Add your phone number',
            'why' => 'Recruiters often call before they write.',
            'fix' => 'add your phone number',
            'findings' => [
                'found' => 'Found: :phone.',
                'missing' => 'No phone number found.',
            ],
            'actions' => [
                'missing' => 'Add your phone number with the country code (for example +212) at the top of your CV.',
            ],
        ],
        'experience_section' => [
            'title' => 'Experience section',
            'suggestion' => 'Add a clearly titled experience section',
            'why' => 'ATS look for a standard heading to find your work history; without one, your experience may not be read as experience.',
            'fix' => 'add a clearly titled experience section',
            'findings' => [
                'found' => 'Heading found: ":heading" (line :line).',
                'missing' => 'No experience heading found (for example "Work Experience" or "Projects").',
                'empty' => 'The heading ":heading" has nothing under it.',
            ],
            'actions' => [
                'missing' => 'Put your work history under a standard heading such as "Work Experience" or "Projects".',
                'empty' => 'List your roles under ":heading": job title, company, dates and 2 to 5 bullets each.',
            ],
        ],
        'education_section' => [
            'title' => 'Education section',
            'suggestion' => 'Add an education section',
            'why' => 'Many job filters check degrees and schools, which ATS find under a standard heading.',
            'fix' => 'add an education section',
            'findings' => [
                'found' => 'Heading found: ":heading" (line :line).',
                'missing' => 'No education heading found (for example "Education").',
                'empty' => 'The heading ":heading" has nothing under it.',
            ],
            'actions' => [
                'missing' => 'Add an "Education" section with your degree, school and dates.',
                'empty' => 'List your degrees under ":heading": degree, school and dates.',
            ],
        ],
        'skills_section' => [
            'title' => 'Skills section',
            'suggestion' => 'Add a skills section',
            'why' => 'ATS and recruiters look for a skills list to match you to the job.',
            'fix' => 'add a skills section',
            'findings' => [
                'found' => 'Heading found: ":heading" (line :line).',
                'missing' => 'No skills heading found (for example "Skills").',
                'empty' => 'The heading ":heading" has nothing under it.',
            ],
            'actions' => [
                'missing' => 'Add a "Skills" section that lists your main tools and skills.',
                'empty' => 'List your main tools and skills under ":heading".',
            ],
        ],
        'dates' => [
            'title' => 'Dates',
            'suggestion' => 'Use clear, consistent dates',
            'why' => 'ATS work out your years of experience from date ranges; missing or mixed formats are misread.',
            'fix' => 'use clear, consistent dates',
            'findings' => [
                'ok' => ':count date ranges, all in one style (:style).',
                'no_experience_section' => 'No experience section, so there are no dates to check.',
                'too_few' => '{0} No date range found in your experience.|{1} Only one date range found in your experience.|[2,*] Only :count date ranges found in your experience.',
                'mixed_styles' => 'Your date ranges use different styles (:styles).',
            ],
            'actions' => [
                'no_experience_section' => 'Add an experience section with a date range for each role.',
                'too_few' => 'Give every role a start and an end date, for example "Mar 2022 – Present".',
                'mixed_styles' => 'Write every date in the same style, for example "Mar 2022 – Present".',
            ],
        ],
        'action_verbs' => [
            'title' => 'Action verbs',
            'suggestion' => 'Start your bullets with action verbs',
            'why' => 'Bullets that start with a strong verb read as achievements, for recruiters and for ATS ranking.',
            'fix' => 'start your bullets with action verbs',
            'findings' => [
                'ok' => ':count of :total bullets start with an action verb.',
                'weak_start' => 'Only :count of :total bullets start with an action verb (60 % needed).',
                'no_bullets' => 'No bullet points found in your experience.',
            ],
            'actions' => [
                'weak_start' => 'Start each bullet with a verb such as "Built", "Led", "Reduced" or "Launched".',
                'no_bullets' => 'Describe each role with 2 to 5 bullet points starting with "•" or "-".',
            ],
        ],
        'quantified_results' => [
            'title' => 'Quantified results',
            'suggestion' => 'Add numbers to your results',
            'why' => 'Numbers (percentages, amounts, counts) make results concrete, and recruiters look for them.',
            'fix' => 'add numbers to your results',
            'findings' => [
                'ok' => ':count bullets include a number.',
                'too_few' => '{0} No bullet includes a number, percentage or amount (2 needed).|{1} Only one bullet includes a number, percentage or amount (2 needed).|[2,*] Only :count bullets include a number, percentage or amount (2 needed).',
                'no_bullets' => 'No bullet points found in your experience.',
            ],
            'actions' => [
                'too_few' => 'Add a measurable result to at least two bullets, for example "cut page load time by 40%" or "handled 120 tickets a week".',
                'no_bullets' => 'Describe each role with 2 to 5 bullet points, including measurable results.',
            ],
        ],
        'length' => [
            'title' => 'Length',
            'suggestion' => 'Adjust the length',
            'why' => 'A CV that is too short looks thin, and one that is too long hides what matters. 250 to 1,000 words suits most roles.',
            'fix' => 'adjust the length',
            'findings' => [
                'ok' => ':words words.',
                'too_short' => ':words words (at least :min recommended).',
                'too_long' => ':words words (at most :max recommended).',
            ],
            'actions' => [
                'too_short' => 'Add detail to your roles: scope, tools used and results.',
                'too_long' => 'Remove older or less relevant roles, and keep each bullet to one or two lines.',
            ],
        ],
        'no_duplicates' => [
            'title' => 'No duplicated bullets',
            'suggestion' => 'Remove duplicated bullets',
            'why' => 'Repeated bullets waste space and look careless.',
            'fix' => 'remove duplicated bullets',
            'findings' => [
                'ok' => 'No duplicated bullets.',
                'duplicates' => '{1} One bullet appears more than once.|[2,*] :count bullets appear more than once.',
            ],
            'actions' => [
                'duplicates' => 'Keep each achievement once, and rewrite repeated bullets so each shows a different result.',
            ],
        ],
        'keyword_coverage' => [
            'title' => 'Job keywords',
            'suggestion' => 'Cover more of the job keywords',
            'why' => 'ATS rank CVs by how many of the job\'s keywords they contain.',
            'fix' => 'cover more of the job keywords',
            'findings' => [
                'complete' => 'All :total job keywords appear in your CV.',
                'partial' => ':matched of :total job keywords appear in your CV (weighted coverage :percent %).',
                'insufficient_job_description' => 'Not scored: too few keywords could be found in the job description.',
            ],
            'actions' => [
                'partial' => 'Add the missing keywords that are true for you; see the keyword suggestions.',
            ],
        ],
    ],

    // Findings of checks that could not be judged (status "unverified").
    'unverified' => [
        'not_inspected' => 'Not checked: pasted text has no layout. Upload the file to check it.',
        'low_confidence' => 'Not checked: the detection was not reliable enough on this file.',
        'no_text' => 'Not checked: no text could be read.',
    ],

    'keywords' => [
        'keyword_missing' => [
            'required' => [
                'title' => 'Missing required keyword: :term',
                'detail' => 'The job description asks for ":term", and it does not appear in your CV.',
                'action' => 'If you have real :term experience, add it to a relevant bullet or to your skills. Do not add skills you do not have.',
            ],
            'preferred' => [
                'title' => 'Missing preferred keyword: :term',
                'detail' => 'The job description lists ":term" as a plus, and it does not appear in your CV.',
                'action' => 'If you have real :term experience, add it to a relevant bullet or to your skills. Do not add skills you do not have.',
            ],
        ],
        'keyword_skills_only' => [
            'title' => 'Show :term in your experience',
            'detail' => '":term" appears only in your skills list. Recruiters give more weight to skills shown in a real role.',
            'action' => 'Mention :term in an experience bullet where you used it, if that is true.',
        ],
        'keyword_stuffing' => [
            'title' => ':term is repeated :count times',
            'detail' => 'Repeating a keyword does not raise your score and can look like keyword stuffing to a recruiter.',
            'action' => 'Keep :term where it shows real use, and remove the repetitions.',
        ],
        'insufficient_job_description' => [
            'title' => 'Paste the full job description',
            'detail' => 'Too few keywords could be found in the job description, so keyword coverage was not scored.',
            'action' => 'Paste the whole job ad, including the requirements and the "nice to have" part.',
        ],
    ],

    'caps' => [
        'major_format_issue' => 'A major layout problem (columns, tables or large images) limits the score to 84.',
        'no_email' => 'No email address was found, which limits the score to 79.',
        'no_experience' => 'No experience section was found, which limits the score to 74.',
    ],

    'summary' => [
        'unreadable' => 'No text could be read from this file, so it cannot be scored.',
        'insufficient_text' => 'Only :words words could be read, which is too little to score this CV.',
        'top_gain' => '{1} :verdict (:score/100); the biggest gain is to :fix (+1 point).|[2,*] :verdict (:score/100); the biggest gain is to :fix (+:count points).',
        'no_gain' => ':verdict (:score/100); no single fix raises it, but the suggestions below still help.',
        'all_passed' => ':verdict (:score/100); every check passes.',
        'verdicts' => [
            'strong' => 'Strong score',
            'good' => 'Good score',
            'needs_work' => 'Your CV needs work',
            'poor' => 'Low score',
        ],
        'fix_keyword' => 'add ":term" if it is true for you',
    ],

    'limitations' => [
        'estimate' => 'This is an estimate of document quality and keyword coverage, not the result of any employer\'s ATS.',
        'pdf_heuristics' => 'Layout detection in PDFs is approximate; each finding shows how confident it is.',
        'no_ocr' => 'Scanned documents are not read (no OCR).',
        'pasted_text' => 'Pasted text has no layout, so the format checks were not run. Upload the file to check them.',
        'other_language' => 'Your CV is not in English or French: keywords are matched only when spelled the same way.',
    ],

    // Notes on the layout signals (§5.2 FormattingReport).
    'formatting' => [
        'common' => [
            'not_inspected' => 'Pasted text has no layout to inspect.',
            'not_applicable_pdf' => 'Does not apply to PDF files.',
        ],
        'columns' => [
            'found' => 'Text is laid out in more than one column.',
            'found_pages' => 'Text is laid out in two columns on page :pages.',
        ],
        'tables' => [
            'found' => '{0} Tables contain text.|{1} One table contains text.|[2,*] :count tables contain text.',
            'possible' => 'Aligned text that looks like a table on page :pages (low confidence).',
        ],
        'images' => [
            'found' => '{1} One image.|[2,*] :count images.',
            'found_area' => '{1} One image, about :largest_area_pct % of the page.|[2,*] :count images, the largest about :largest_area_pct % of the page.',
        ],
        'text_boxes' => [
            'found' => '{0} Text boxes contain text.|{1} One text box contains text.|[2,*] :count text boxes contain text.',
        ],
        'header_footer' => [
            'found' => 'Some text is in the page header or footer.',
            'contact_only' => 'Contact details appear only in the page header or footer.',
            'single_page' => 'Single-page PDF: there is no repeated header or footer to detect.',
        ],
        'glyph_issues' => [
            'found' => 'Icons, unreadable characters or letter-spaced headings found.',
        ],
    ],

    'date_styles' => [
        'month' => 'Mar 2022',
        'numeric' => '03/2022',
        'year' => '2022',
    ],
];
