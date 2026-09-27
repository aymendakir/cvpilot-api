# ATS Checker v3 — frontend and backend map

## What the page measures
`/ats-checker` reports a deterministic **document quality** score for the exact extracted CV text. It is not a score from an employer ATS, an interview probability, or a certification of the original PDF's layout. Upload and extraction run in the browser; sign-in is required before the report. AI adds optional writing, line edits and job-specific feedback, but cannot change the numeric score.

The CV's detected language (French or English) sets the check findings/actions and AI feedback. A job description is optional, and does not influence the document score. The same text always produces the same document report. If AI is down, the document report and all check details stay available.

## Frontend source map
- `app/ats-checker/page.tsx`: five tabs: Overview (score, four highest-impact fixes and AI feedback), Line edits (review then accept/dismiss), Document checks (four weighted categories, each check with pass/fail, points, finding, evidence and action), Job fit (optional evidence against offer), Your document (original extracted text, editable draft, PDF link, rerun). Login, upload, error and retry states live here. Changing the draft marks the previous report stale.
- `app/ats-checker/audit.css`: responsive page, tabs, cards and check-detail styles.
- `lib/ats-document.ts`: validates the versioned server report and verifies point totals before showing it. A mismatched backend version shows an explicit error, never a made-up score.
- `lib/read-cv.ts` and `lib/pdf-reading-order.ts`: local PDF, DOCX and TXT extraction. PDF.js text items are sorted by page coordinates before scoring because some PDF generators group all headings before the body. Multi-column layouts and employer-specific parsing still require human review.
- `lib/resume-audit.ts`: validates optional AI structured review and source quotes. AI writing dimensions are not numeric input to the document score.
- `lib/backend-api.ts`: session cookie, CSRF and API origin.

## Backend source map
- `routes/web.php`: protected, throttled `POST /api/ats/document` and separate `POST /api/ai/ats-analysis`.
- `app/Http/Controllers/AtsDocumentController.php`: validates `cv_text` (30–30,000 characters) and optional `file_name`; returns report JSON.
- `app/Services/AtsDocumentReview.php`: observable English/French heading, extraction, contact and contribution rules; scoring, caps and per-check actions. French action nouns and letter-spaced headings receive explicit checks.
- `app/Services/ResumeLanguage.php`: CV language detection.
- `app/Http/Controllers/AiController.php` + `app/Services/ResumeReview.php`: optional source-grounded AI writing review, not used to calculate score.
- No migration or new key is needed for document checks. An enabled AI integration is required only for writing advice.

## Score rules (version `document-3`)
| Category | Checks and maximum points | Total |
| --- | --- | ---: |
| Extracted text | readable 5, character integrity 5, reading continuity 5 | 15 |
| Contact | readable email 10 | 10 |
| Sections | experience/projects 10, education 5, skills 5, dates 5 | 25 |
| Experience evidence | distinct contributions 15, clear actions 10, concrete wording 5, focused sentences 5, no duplicates 5, outcomes/context 10 | 50 |

Raw points are the sum of earned points. With fewer than 40 readable words, `score` is `null` (show the findings but no number). Every failed check caps the displayed score at 89. No email caps at 80; no recognized experience heading caps at 78; fewer than two distinct substantive contributions caps at 82; damaged replacement characters or severe single-letter extraction caps at 72. These caps reflect the current backend rules. All observed checks passing yields a maximum 94, because employer ATS parsing and layout remain untested. No numeric results or successes should be invented just to raise the score. The rules inspect phrases, not truth, and their patterns can miss unusual headings or language variants. Update the version constant and frontend parser together when changing the contract.

Every check returns `status`, `earned/max`, `finding`, `action`, and up to two exact lines from the submitted CV. `priorities` includes up to four failing checks ordered by lost points. Passing checks also explain what was found, with source evidence where available. The original CV remains unchanged.

## API contract
Authenticated `POST /api/ats/document` with JSON `{ "cv_text": "Experience\\n...", "file_name": "cv.pdf" }`. Success is JSON `{ "version":"document-3", "score": 74, "raw_score": 82, "max_score":100, "words": 236, "issues":3, "score_reason":"...", "categories":[{"id":"extraction","title":"...","description":"...","score":15,"max":15,"checks":[{"id":"readable","title":"...","status":"pass","earned":5,"max":5,"finding":"...","action":"...","evidence":[]}]}], "priorities":[...], "limitations":["..."] }`. Values here illustrate shape, not a measured CV. A signed-out request receives 401, an invalid input 422, and throttling 429. `POST /api/ai/ats-analysis` accepts `cv_text`, optional `job_description`, and `report_format:"structured"` and returns an independently validated `review`.

## Change and deploy
Edit the scoring rules in `AtsDocumentReview.php`, then update the matching score explanation and schema in `page.tsx` / `lib/ats-document.ts`; preserve the 100-point sum and the 90+ invariant. Edit the look in `audit.css`, the report layout and text in `page.tsx`, and AI suggestion rules separately in `ResumeReview.php`. Run `php tests/ats-document.php` in the backend container, `node tests/ats-document.mjs`, `node tests/pdf-reading-order.mjs` and `npm run build` in the frontend. Deploy **backend first**, verify `php artisan route:list --path=api/ats/document`, then build/deploy the frontend with `NEXT_PUBLIC_BACKEND_URL` set to the public HTTPS API URL. Check a French and an English CV, a sparse and a rich CV, no AI provider, session expiration, mobile PDF picker, and report rerun after editing. Native PHP and physical iPhone verification are still required in the user's environment.
