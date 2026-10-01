# Implementation Plan: Phase 3, slice S1 — `ats-language` + `ats-parsing`

Spec: `SPEC-ats.md` §2 (modules), §3, §4 (pipeline, §4.1 signals), §7 (structure), §8.3 (stems), §10–§11, §17. Input: `docs/ats-spike-s0.md`. S0 is merged. This plan covers **S1 only**.

Branch: `feature/ats-s1` (from `main`). One PR. Stop after opening it.

## Decisions already made (after S0)

- **PDF layout comes from poppler** (`poppler-utils` in the Docker image): `pdftotext -bbox-layout` for text and line positions. smalot stays only if still useful for text; the spike says it isn't (poppler extracts the same text, 2–50× faster), so this plan removes it (decision 1 below).
- A test **fails** (not skips) when the poppler binaries are missing; `docs/DEPLOYMENT.md` documents the dependency.
- Missing preferred keywords are severity `info` (confirmed; S3 applies it).
- Spike findings applied in S1/S2: header/footer = **repeated on every page**, **symbol bullets at line starts are not glyph issues**, image **area threshold** (15 % of the page, §6).

## What I checked before planning

- smalot is used in exactly one place outside tests: `App\Services\DocumentExtractor` (the live `POST cv-documents` and `cv-documents/extract`), PDF branch only. The legacy engine (`AtsDocumentReview`, `AtsScorer`) works on extracted text and never touches smalot.
- `wamania/php-stemmer` v4.0.0 (MIT) brings one transitive package, `joomla/string`. `ext-intl` is in CI already but not in the Docker image.
- `pdfimages -list` reports each image's pixel size **and its resolution (ppi)**, so the placed size (pixels ÷ ppi × 72 pt) and its share of the page come without writing any image file to disk. `pdftohtml -xml` also gives positions but writes the image files to the working directory, which we do not want for CV photos.
- Laravel's `Process` facade (Symfony Process) is available; no new dependency to call the binaries.

## Design

```text
app/Services/Ats/
  Language/  Normalizer, Tokenizer, StopWords, Stemmer, LanguageDetector
  Parsing/   DocumentParser (interface), DocumentTypeDetector, TextParser, DocxParser,
             PdfParser, Poppler (process wrapper), ParsedDocument, Line, Structure,
             Detection, Confidence (enum), UnreadableDocument (exception with a reason)
config/ats.php                 # model version, poppler paths, timeouts, thresholds of §4.1
resources/ats/stopwords.{en,fr}.txt
```

- **`ParsedDocument`**: `type` (`pdf|docx|text`), `text`, `lines[]` (text, page, y, x when known), `pages`, `wordCount`, `textExtractable`, `structure` (null-safe: `inspected=false` for pasted text), `warnings[]`. No file names or paths are stored in it, only what the report needs.
- **`Detection`** per signal: `detected: ?bool`, `confidence: high|medium|low|null`, `note`, plus extras (`count`, `largestAreaPct`, `contactOnlyThere`, evidence samples ≤ 3).
- **`UnreadableDocument`** reasons: `password_protected`, `corrupt`, `unsupported_type`, `timeout`. S4 maps them to `422 errors.file`. A scanned PDF is **not** an exception: it parses to `textExtractable=false` (R4, `unreadable` report).
- **Poppler wrapper**: argument arrays (no shell), a hard timeout from `config('ats.poppler.timeout')` (default 10 s), output size cap, reads the request's temp file only. `pdfinfo` → pages, encryption; `pdftotext -bbox-layout -enc UTF-8` → words and lines with boxes; `pdfimages -list` → images and placed area.

### §4.1 signals as built in S1

| Signal | DOCX (ZIP + XML, no PhpWord) | PDF (poppler) |
| --- | --- | --- |
| Columns | `w:cols` `w:num > 1` / several `w:col` → high | spike heuristic on line starts: clusters ≥ 8 lines, ≥ 25 % width apart, ≥ 50 % vertical overlap → **medium**; **high** when each cluster ≥ 12 lines and overlap ≥ 80 %; a near miss (5–7 lines) → low |
| Tables | `w:tbl` containing text → high | ≥ 3 consecutive rows of ≥ 3 aligned cells → **low** (spec §4.1; the check reports `unverified`) |
| Images | `w:drawing` / `w:pict`, size from `wp:extent` or VML style vs `w:pgSz` → high | `pdfimages -list`, area from pixels ÷ ppi → medium (cropping is not seen) |
| Text boxes | `w:txbxContent`, `v:textbox`, `wps:txbx` → high | n/a (`detected: null`) |
| Header/footer | text in `word/header*.xml` / `footer*.xml` → high; `contact_only_there` when email/phone appear there and not in the body | **text in the top/bottom 7 % band that repeats on every page** (digits normalized, so page numbers count) of a ≥ 2-page PDF → medium; a 1-page PDF → `detected: false`, medium (decision 2) |
| Glyph issues | private-use / `U+FFFD` characters and letter-spaced headings, **ignoring a private-use character that is the first character of a line (list bullet)** | same |
| Pages | `docProps/app.xml` if present, else `null` | `pdfinfo` |

DOCX text keeps body order, includes table cells and text boxes, and reads only `mc:Choice` (not `mc:Fallback`) so text boxes are not duplicated.

### Language

- `Normalizer`: NFKC, lower-case, an accent-folded comparison form (`intl` Transliterator) next to the display form.
- `Tokenizer`: token boundaries that keep tech tokens whole (`c#`, `.net`, `node.js`, `ci/cd`, `a/b`, `c++`).
- `StopWords`: EN/FR lists in `resources/ats/`.
- `Stemmer`: Snowball EN/FR via `wamania/php-stemmer`.
- `LanguageDetector`: `en | fr | other` from stop-word and marker ratios (reuses the marker idea of `ResumeLanguage`, which itself stays untouched for the legacy engine).
- **§8.3 stem check:** a unit table records the actual stems for every §8.3 row. The spec says rows the library contradicts are fixed in the spec and listed in the PR. One is already suspicious: Snowball stems "going" to "go", so `Go` vs "going to the office" would be a stem match unless S2 skips stemming for short or tech terms. S1 records the facts; S2's matcher decides (and the spec row stays as the acceptance target).

## Tasks

### T1 — Tooling: poppler, intl, stemmer, config
- Dockerfile: `poppler-utils`, `libicu-dev` + `docker-php-ext-install intl`. CI: `apt-get install -y poppler-utils` before the tests.
- `composer require wamania/php-stemmer:^4.0` and `"ext-intl": "*"` in `require` (a missing extension then fails `composer install`).
- `config/ats.php` (version `ats-2.0`, poppler binary paths and timeout, §4.1 thresholds).
- `PopplerAvailabilityTest`: **fails** when `pdftotext`, `pdfinfo` or `pdfimages` is missing or too old (needs `-bbox-layout`), with a message that says how to install it.
- `docs/DEPLOYMENT.md` (system dependency, image size note, what fails without it) and README (local install: `apt install poppler-utils` / `brew install poppler`).
- **Acceptance:** CI green with poppler installed; the test fails with a clear message when `ATS_PDFTOTEXT` points to a missing binary; `docker build` succeeds and `docker run … pdftotext -v` works (I will run the build if Docker is available in the session; otherwise the PR says it was not run).

### T2 — `ats-language`
- Normalizer, Tokenizer, StopWords (+ files), Stemmer, LanguageDetector, with unit tables (EN/FR accents, tech tokens, token boundaries `Java`/`JavaScript`, the §8.3 stems, language on BASE-EN/BASE-FR/F9 and an "other" sample).
- **Acceptance:** unit tests green; §8.3 stem facts listed for the PR.

### T3 — Parsing core + text and DOCX parsers
- DTOs, `DocumentTypeDetector` (content-based; same rules as `DocumentExtractor::detect`, which later uses it), `TextParser`, `DocxParser` with the DOCX signals above.
- **Acceptance:** every DOCX fixture and the F9 text give the expected signals (clean: none; `table-layout.docx`: tables high; `photo-icons.docx`: 1 image ≈ 2.6 % of the page and glyph issue; `photo-icons-fixed.docx`: no glyph issue; F9: `structure_inspected=false`); `legacy.doc` and `renamed-exe.pdf` → `unsupported_type`.

### Checkpoint A
- [ ] Review with maintainer: DTO shapes, DOCX results table, §8.3 stem facts.

### T4 — Poppler wrapper and `PdfParser`
- Process wrapper (timeouts, no shell, output cap); `PdfParser` with the PDF signals above, including the repetition rule for header/footer and the bullet rule for glyphs.
- **Acceptance:** `clean-en.pdf` none; `two-column.pdf` columns on both pages (≥ medium); `table-layout.pdf` tables (low); `scanned.pdf` `textExtractable=false`, 1 image ≈ 100 %; `encrypted.pdf` → `password_protected`; `corrupt.pdf` → `corrupt`; a forced timeout → `timeout`.

### T5 — Fixture-wide parsing tests, budgets, privacy
- One data-provider test over `manifest.json` + the extra fixtures asserting the §4.1 signals per file; a time budget (F1/F2 parse ≤ 1.5 s); no file left behind in the temp dir after parsing (poppler writes nothing; asserted).

### T6 — Drop smalot (decision 1)
- `DocumentExtractor`'s PDF branch uses the new `PdfParser` text; `FixturesTest` reads PDFs through it; `composer remove smalot/pdfparser`; delete `tests/fixtures/ats/spike/` (as agreed in S0).
- **Behaviour check:** a characterization test pins today's `cv-documents/extract` result on the fixture PDFs (same vocabulary, same error codes and messages for encrypted/corrupt/scanned) before the switch, and must stay green after it. `tests/legacy/cv-extract.php` must still pass.

### T7 — Spec and docs, PR, STOP
- `SPEC-ats.md`: §3 (poppler, smalot gone), §4.1 table as built, header/footer and bullet rules, §12 R1 resolved; `tasks/todo.md`; open the PR.

### Checkpoint B (final)
- [ ] PR opened; S2 (`ats-checks` + `ats-keywords`) planned after merge.

## Risks

| Risk | Mitigation |
| --- | --- |
| Poppler parses untrusted PDFs | argument arrays, no shell, timeout, output cap, 15 MB upload limit; Debian package kept current through the base image |
| Image grows | `poppler-utils` adds roughly 10–20 MB to the image; stated in DEPLOYMENT.md |
| Switching `cv-documents` to poppler changes extracted text | characterization test before/after (T6); decision 1 lets you keep smalot there instead |
| Snowball stems short words aggressively (`going` → `go`) | recorded in T2; S2 matcher rule; §8.3 stays the acceptance target |
| DOCX variants (Google Docs, LibreOffice exports) differ from PhpWord output | parser reads raw XML, not PhpWord; S5 calibration CVs will include DOCX if you have them |

## Decisions needed (recommendation first)

1. **Remove smalot in S1** (T6): the live CV upload/extract switches to poppler text, guarded by a characterization test, and `smalot/pdfparser` leaves `composer.json`. *Recommended:* one PDF engine, faster uploads, and you asked to drop it unless it is still useful. Alternative: keep smalot for `DocumentExtractor` until the legacy-removal PR.
2. **Header/footer on one-page PDFs:** report `detected: false` (medium), since a PDF has no separate header layer that an ATS would drop and the band test only finds names there. The `text_boxes_headers` check then still runs on the DOCX header/footer parts (high confidence) and on repeated bands in multi-page PDFs. *Recommended.* Alternative: `unverified` for every one-page PDF (most CVs), which removes the check for most users.
3. **PDF image area from `pdfimages -list` (pixels ÷ ppi)**, which writes nothing to disk but ignores cropping, so a cropped photo can look larger than it is. *Recommended*, with medium confidence. Alternative: `pdftohtml -xml` in a temp directory deleted in `finally` (exact placement, but the photo is briefly written to disk).

## Out of scope

Checks and scoring (S2/S3), the route (S4), the keyword taxonomy and synonyms (S2), OCR, anything in `cv-ai`.
