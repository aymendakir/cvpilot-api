# Implementation Plan: Phase 3, slice S0 — PDF spike and fixtures

Spec: `SPEC-ats.md` (approved), §4.1 (parsing signals), §8 (fixtures and expected scores), §12 R1, §16 (slices), §17 answers 2 and 3. Phase 2 (S0–S7, Phase 2b) is merged and live. This plan covers **S0 only**.

Branch: `feature/ats-s0` (from `main`). One PR. Stop after opening it.

## Overview

S0 does two things before any engine code exists:

1. **Fixtures.** Build every CV and job-description fixture that §8 scores, with a reproducible `tests/fixtures/ats/build.php`, and write the expected results from §8 as `expected/*.json`. These become the golden tests that S1–S4 must turn green; S0 only checks that the fixtures *are what they claim to be* (a two-column PDF really has two columns, F9 really has ~150 words, and so on).
2. **Spike (risk R1).** Find out whether `smalot/pdfparser` gives enough position data to detect columns, tables, header/footer text and images in PDFs. The answer decides whether S1 builds the PDF parser on smalot alone or whether I ask you for poppler (§17 answer 3).

No `app/` code, no route, no Docker change in S0 (`ext-intl` and the stemmer come in S1).

## What I verified before planning

- `smalot/pdfparser` v2.12.5 exposes `Page::getDataTm()` (text runs with their transformation matrix, i.e. x/y positions) and `Page::getXObjects()` (images). That is the raw material the spike tests.
- `gd`, `zip`, `xml` are available locally, so `build.php` can generate the profile photo and the "scanned" page image without new extensions.
- `phpoffice/phpword` is already a dependency (DOCX fixtures); `setasign/fpdf` is not (added as dev, §17 answer 2).

## Fixture design

**Content.** One fictional profile (no real person): **BASE-EN** is a PHP/Laravel developer, ~480 words: name, email, phone; Experience with 3 roles (`MMM YYYY – MMM YYYY`), 10 bullets starting with action verbs, 4 with quantified results; Education; Skills. The wording is chosen so §8.2 is exact: it contains PHP, Laravel, MySQL, Docker, PHPUnit, "RESTful API", "GitHub", "continuous integration", "Vue"; it never mentions Redis, AWS, Kubernetes, GraphQL or Terraform. **BASE-FR** is the same profile in French with *Expérience professionnelle*, *Formation*, *Compétences*, containing PHP, Symfony, MySQL, Docker, "gestion de projets", "tests unitaires" and no Vue. Content lives in `tests/fixtures/ats/content/*.php` so every variant is derived from one source.

**Files** (`tests/fixtures/ats/cvs/`):

| Fixture | Spec | How it is built |
| --- | --- | --- |
| `clean-en.docx` | F1, F3, F13 | PhpWord, BASE-EN |
| `clean-en.pdf` | F2; also the corrected variant of F4 | FPDF, BASE-EN, single column |
| `two-column.pdf` | F4, F4b | FPDF, skills + contact in a left sidebar, experience in the right column, 2 pages |
| `table-layout.docx` | F5 | PhpWord, whole CV in a 2-column table |
| `table-layout.pdf` | spike only | FPDF, ruled grid table layout (R1 needs a PDF table case) |
| `scanned.pdf` | F6 | FPDF, one page that is only a GD-rendered image of the CV, no text layer |
| `photo-icons.docx` | F7 | PhpWord, 4 cm GD-generated photo, private-use icon glyphs (U+F0E0, U+F095) before email/phone |
| `photo-icons-fixed.docx` | F7 corrected | same, icons replaced by "Email:" / "Phone:" |
| `missing-sections.docx` | F8 | no Education, no Skills, no phone |
| `missing-sections-plus-education.docx` | F8 corrected | F8 + Education |
| `no-email-no-exp.txt` | F9 | ~150 words, phone, Education, Skills; no email, no experience heading, no bullets |
| `too-long.docx` | F12 | BASE-EN extended to ~1 400 words / 4 pages |
| `clean-fr.docx` | F11 | PhpWord, BASE-FR |
| `stuffing.docx` | §8.4 | BASE-EN + "Laravel" repeated to 15 occurrences |

The F5 corrected variant is `clean-en.docx` (same content, no table). Error-case files (encrypted, corrupt, `.doc`, renamed `.exe`, 16 MB) belong to S4's API tests; corrupt/renamed/`.doc` are tiny and built in S0, the 16 MB file is generated at test time (never committed), and the **encrypted PDF** is an open point (decision 3).

**Jobs** (`tests/fixtures/ats/jobs/`): `laravel-dev.txt` (F3), `laravel-dev-short.txt` (F4b: 6 required + 4 preferred, worded so the two-column CV matches 5 + 2), `unrelated-marketing.txt` (F13), `dev-symfony-fr.txt` (F11).

**Expected results** (`tests/fixtures/ats/expected/<case>.json`): one file per §8.1/§8.2 row with exactly the subset §11 pins (`score`, `raw_score`, `grade`, `score_status`, per-check `status`/`earned`, keyword `status`/`match_type`, `caps`, top-3 suggestion ids and `impact_points`), copied from the spec tables, plus `manifest.json` mapping each case to its CV, job, mode and corrected variant. Nothing reads them as golden tests until S3/S4; S0 only validates their shape and that the numbers obey R1/R3/R5 arithmetic (a pure test over the JSON, so a typo in the spec tables is caught now).

**Reproducibility.** FPDF and PhpWord embed creation dates and ZIP timestamps, so bytes differ between runs. `build.php` pins the dates it can (document properties, FPDF creation date) and the sanity test compares **content and structure**, not bytes: re-running `build.php` must not change the extracted text or the structural facts below.

## Fixture sanity test (`tests/Feature/Ats/FixturesTest.php`)

Checks each committed fixture with today's libraries, independent of the future engine:

- DOCX: `word/document.xml` contains `w:tbl` only in `table-layout.docx`; `w:drawing` only in `photo-icons*.docx`; PUA glyphs only in `photo-icons.docx`; headings present/absent as the table says (F8: no Education/Skills).
- PDF: page counts; `scanned.pdf` has no extractable text and one image XObject; `clean-en.pdf` and `two-column.pdf` extract the same words (as a set) as `clean-en.docx`.
- Word counts within ±5 % of the target (480, 150, 1 400); F9 has a phone but no `@`.
- Jobs: required/preferred terms present under the right headings.
- `expected/*.json`: schema of the pinned subset, and `raw_score = round_half_up(100 × Σearned / Σmax)`, cap rule R3 and the corrected-variant arithmetic (current + impact = corrected score).

## Spike (`tests/fixtures/ats/spike/`)

A throwaway probe script, not app code: `probe.php <file.pdf>` prints, per page, the text runs with x/y from `getDataTm()`, then tries the §4.1 heuristics:

- **Columns:** cluster line-start `x`; ≥ 2 clusters, each ≥ 8 lines, gap ≥ 25 % of page width, vertical overlap ≥ 50 %.
- **Tables:** ≥ 3 consecutive lines with ≥ 3 aligned cells.
- **Header/footer:** text in the top/bottom 7 % of the page.
- **Images:** image XObjects, pixel size, share of page area.
- Timing per file.

Run on `clean-en.pdf`, `two-column.pdf`, `table-layout.pdf`, `scanned.pdf`, and on real PDFs if you provide them (decision 1; they stay outside the repo). Output: `docs/ats-spike-s0.md` with a results table (expected vs detected vs confidence, time), what smalot cannot see, and a recommendation: **smalot is enough** (S1 builds on it) **or poppler is needed** (I stop and ask you, per §17 answer 3). The probe script is deleted in S1 once its heuristics move into `app/Services/Ats/Parsing` (decision 2).

## Tasks

### T1 — `setasign/fpdf` (dev), content sources, `build.php`, clean fixtures
- `composer require --dev setasign/fpdf`; BASE-EN / BASE-FR content files; `build.php` builds `clean-en.docx`, `clean-en.pdf`, `clean-fr.docx`.
- **Acceptance:** `php tests/fixtures/ats/build.php` regenerates them; `composer audit` clean; word count ~480; the three files extract the same text content.
- **Verify:** `composer test`, `pint --test`, `composer audit`.

### T2 — Problem variants and corrected variants
- Two-column PDF, table layouts (DOCX + spike PDF), scanned PDF, photo/icon DOCX (+ fixed), missing sections (+ education), too-long, stuffing, F9 text; tiny corrupt / `.doc` / renamed-`.exe` files.
- **Acceptance:** every row of the fixture table exists and is rebuilt by `build.php`.

### T3 — Jobs, expected results, manifest, `FixturesTest`
- Four job descriptions; `expected/*.json` for F1–F13 + F4b; `manifest.json`; `FixturesTest` as above.
- **Acceptance:** `FixturesTest` green; the arithmetic test reproduces every number in §8.1/§8.2 (any mismatch is a spec error: I fix the spec table and list it in the PR, never silently).

### Checkpoint A
- [ ] Review with maintainer: fixture list, BASE-EN/BASE-FR text (FR wording is yours to review), expected values.

### T4 — Spike probe
- `spike/probe.php` with the four heuristics and timing; run on the generated PDFs (and real PDFs if provided).
- **Acceptance:** results recorded for every file; no app code added.

### T5 — Spike report, PR, STOP
- `docs/ats-spike-s0.md` (results, limits, recommendation); `tasks/todo.md` updated; open the PR. If the recommendation is poppler, the PR says so and nothing is installed.

### Checkpoint B (final)
- [ ] PR opened; you decide smalot vs poppler; S1 is planned after merge.

## Risks

| Risk | Mitigation |
| --- | --- |
| Generated PDFs are cleaner than real CVs (Canva, Word export), so the spike looks better than reality | Real PDFs from you (decision 1); the report states which results come from generated files only |
| FPDF cannot produce an encrypted PDF | Decision 3 |
| Fixture text drifts from the §8 numbers (a keyword accidentally present) | `FixturesTest` asserts the presence/absence lists for every job term |
| Committed binaries bloat the repo | All fixtures are small (target < 100 KB each, < 1 MB total); the 16 MB file is never committed |

## Decisions needed (recommendation first)

1. **Real PDFs for the spike:** send me 3–5 anonymized real CV PDFs now (ideally Canva and Word exports, at least one with a sidebar), separate from the 20 for S5 calibration. They are used locally only and never committed. *Recommended*: generated PDFs alone can't tell us whether smalot copes with real exports, which is the actual R1 risk. If you prefer not to, the spike runs on generated files and the report says so.
2. **Spike code:** throwaway script under `tests/fixtures/ats/spike/`, deleted in S1 when the heuristics move into `app/`. *Recommended* over writing the real `PdfParser` now (that is S1's job, with tests).
3. **Encrypted PDF fixture:** build a minimal password-protected PDF by hand-writing the encryption dictionary in `build.php` (no new dependency). *Recommended.* Alternative: add `setasign/fpdi-protection`-style code or a `qpdf` binary in CI only (both are new dependencies, so they need your approval).

## Out of scope

Engine code (`app/Services/Ats`), the route, Docker/`ext-intl`/stemmer (S1), golden tests that run the engine (S3/S4), the prompt envelope (S6), any UI.
