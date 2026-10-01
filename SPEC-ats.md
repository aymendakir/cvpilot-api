# Spec: Phase 3 — ATS checker rebuild

- **Repo:** `cvpilot-api` (engine + API). Phase 3b is **skipped**: the ATS report UI is built in `cv-ai` in **Phase 4** (UI/UX), on the response shape fixed here (§13).
- **Status:** **APPROVED** (answers recorded in §17). Delivery is one branch + one PR per slice (§16), merged by the maintainer before the next slice starts. Plan: `tasks/plan.md`, task list: `tasks/todo.md`.
- **File name:** `SPEC-ats.md`; `SPEC.md` is the Phase 2 (API refactor) spec.
- **Phase 2 is merged and live** (S0–S7 on `cvpilot-api`, Phase 2b on `cv-ai`): the error envelope (`{message, code, errors, request_id}`), `/api/v1` routes, FormRequests and the PHPUnit harness this phase builds on are in `main`. The legacy `/api/*` aliases no longer exist.
- **Scope rule:** this is the core of the product. The UI must be built on what this API returns, so §5 (response shape) and §8 (fixtures with expected scores) are the contract.

## 1. Objective

Replace today's text-only checker with an engine that analyses **the actual file** and returns a **deterministic, explainable score with actionable suggestions**:

1. **Parse CV files** (PDF, DOCX) on the server, plus pasted text.
2. **Extract keywords from a job description** (no AI).
3. **Match keywords** against the CV with synonyms and stemming (English + French).
4. **Section checks:** contact, experience, education, skills, dates.
5. **Detect ATS-unfriendly formatting:** multiple columns, tables, images, text boxes, contact only in header/footer, icon glyphs, scanned/no text.
6. Return **one score (0–100 or `null`)**, per-check results with evidence, and **prioritized suggestions ranked by real score impact**.

**Users:** job seekers (signed-in members) checking a CV, optionally against one job; the `cv-ai` UI that renders the report.

**Success looks like:** the same file + job always gives the same report; every point lost is explained by a check with evidence and a concrete fix; fixing the top suggestion raises the score by exactly the advertised amount.

**Non-goals:** simulating any real employer ATS (the report says so), OCR, AI scoring (the existing AI review stays a separate optional call and never changes the score), job search, report history/persistence (Phase 5), legacy `.doc`.

## 2. Capability map (Phase 0 of the spec skill) — please approve before module specs

This request bundles several independently testable capabilities, so module boundaries come first. After you approve the map, each module gets `SPEC-ats-<id>.md` (objective, interfaces, tests) in build order; the cross-module contract (response shape, scoring model, fixtures) lives here.

| Module id                   | Responsibility                                                                                                                                      | Depends on                    |
| --------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------- |
| `ats-parsing`               | PDF / DOCX / text → `ParsedDocument` (text, lines, pages, structure signals: columns, tables, images, text boxes, header/footer text, glyph issues) | —                             |
| `ats-language`              | Language detection (EN/FR), normalization (case, accents, Unicode), tokenization, stop words, Snowball stemming                                     | —                             |
| `ats-keywords`              | Job-description keyword extraction + synonym/stem-aware matching with evidence, stuffing detection                                                  | `ats-language`                |
| `ats-checks`                | Format, section and content checks → `CheckResult[]`                                                                                                | `ats-parsing`, `ats-language` |
| `ats-scoring`               | Weights, normalization, caps, grade, what-if impact, suggestion ranking, message catalog (EN/FR)                                                    | `ats-checks`, `ats-keywords`  |
| `ats-api`                   | `POST ats/analyses`, FormRequest, report Resource, JSON Schema, error mapping                                                                       | `ats-scoring`                 |
| `ats-ui` (Phase 4, `cv-ai`) | Report UI built on the schema                                                                                                                       | `ats-api`                     |

Build order: `ats-parsing` ∥ `ats-language` → `ats-keywords` ∥ `ats-checks` → `ats-scoring` → `ats-api` → `ats-ui` (Phase 4). No cycles. A **spike** (S0) on `ats-parsing` comes first because PDF structure detection is the main technical risk (§12 R1).

## 3. Assumptions (confirmed)

1. Laravel 12 / PHP 8.3 (`php:8.3-apache` image). PDFs are read with **poppler** (`poppler-utils`: `pdftotext -bbox-layout`, `pdfinfo`, `pdfimages`), installed in the image since S1; `smalot/pdfparser` was removed in S1 (the S0 spike showed it cannot see Canva layouts, `docs/ats-spike-s0.md`). DOCX is read from its raw XML (`ext-zip`, `ext-dom`). `ext-intl` and `wamania/php-stemmer` were added in S1 (§17 answer 1).
2. The server receives the **file**; analysis is **stateless** — the upload lives only in a temp file for the request, is deleted in a `finally`, is never stored or logged, and no AI provider is called.
3. Languages v1: **English and French** (detected from the CV; job description may differ). Any other language → still analysed with exact matching only and `language.supported=false`.
4. Scores are **estimates of document quality and keyword coverage**, not an employer ATS result (shown in `limitations`).
5. Message language follows the CV language (`locale` can override): `en`, `fr`.
6. The existing `POST /api/v1/ats/document` endpoint (`AtsDocumentReview`, `AtsScorer`) keeps working untouched. It is removed, together with `tests/legacy/ats-document.php`, in a **follow-up PR after the new engine is live and the Phase 4 UI uses it** (§17 answer 6). `ai/ats-analysis` stays as the on-demand AI review (§17 answer 8).

## 4. Analysis pipeline

```
input (file | cv_text) + optional job_description
  → ats-parsing  : ParsedDocument { text, lines[], pages, words, structure{…}, warnings[] }
  → ats-language : language, tokens, stems
  → ats-checks   : A format · B sections · C content  (CheckResult[])
  → ats-keywords : D keywords (only when a job description is given)
  → ats-scoring  : score, caps, grade, suggestions (what-if ranked)
  → ats-api      : AtsReport JSON (§5)
```

### 4.1 Parsing signals (`structure`)

| Signal        | DOCX (ZIP + XML; confidence high)                                                                                                                                                                                                 | PDF (poppler)                                                                                                                                                                                                               | Text paste |
| ------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------- |
| Columns       | `w:sectPr/w:cols` with `w:num > 1` or `w:col` count > 1                                                                                                                                                                           | `pdftotext -bbox-layout` line boxes. Per page, cluster line-start `x`; ≥ 2 clusters, each ≥ 8 lines, gap ≥ 25 % of page width, vertical overlap ≥ 50 % → medium; each ≥ 12 lines and overlap ≥ 80 % → high; 5–7 lines → low | unverified |
| Tables        | any `w:tbl` containing text                                                                                                                                                                                                       | ≥ 3 consecutive rows with ≥ 3 aligned cells (low confidence)                                                                                                                                                                | unverified |
| Images        | `w:drawing` / `w:pict` (`wp:extent` or VML size vs `w:pgSz`)                                                                                                                                                                      | `pdfimages -list`: count, placed area = pixels ÷ ppi × 72 vs page area (cropping not visible → medium). No image is ever extracted or written                                                                               | unverified |
| Text boxes    | `w:txbxContent` (DrawingML or VML box; the `mc:Fallback` copy is ignored)                                                                                                                                                         | n/a                                                                                                                                                                                                                         | unverified |
| Header/footer | text in `word/header*.xml`, `word/footer*.xml`; `contact_only_there` when email/phone appear only there                                                                                                                           | text in the top/bottom 7 % band **that repeats on every page** (digits ignored, so page numbers repeat) of a ≥ 2-page PDF → medium; a **one-page PDF: not detected** (no separate header layer; the band only holds names)  | unverified |
| Glyph issues  | private-use / replacement characters (`U+E000–F8FF`, `U+FFFD`), letter-spaced headings. A private-use character that starts **≥ 3 lines** is a symbol-font list bullet and is ignored; contact icons (each used once) still count | same                                                                                                                                                                                                                        | same       |
| Pages         | `docProps/app.xml` if present, else `null`                                                                                                                                                                                        | `pdfinfo`                                                                                                                                                                                                                   | `null`     |

Each structure finding carries `confidence: high | medium | low`. A check **fails** only with confidence ≥ medium; low confidence yields `status: "unverified"` plus an `info` suggestion. Encrypted/corrupt files, unsupported types and a poppler timeout (`ATS_POPPLER_TIMEOUT`) are `422` (Phase 2 envelope; `UnreadableDocument` reasons `password_protected`, `corrupt`, `unsupported_type`, `timeout`); a missing poppler binary is a `500`, never blamed on the file; scanned PDFs (no extractable text) are a **`200` report** with `score_status: "unreadable"`, because the report itself tells the user how to fix it.

## 5. API contract

### 5.1 Request

`POST /api/v1/ats/analyses` — authenticated (session), throttled 20/min/user. `multipart/form-data` or JSON.

| Field             | Type                   | Rules                                                                                             |
| ----------------- | ---------------------- | ------------------------------------------------------------------------------------------------- |
| `file`            | file                   | PDF or DOCX detected from **content** (not name/MIME); ≤ 15 MB. Exactly one of `file` / `cv_text` |
| `cv_text`         | string                 | 30–30 000 chars. Structure checks become `unverified`                                             |
| `job_description` | string, optional       | 60–30 000 chars. Enables `mode: "job_match"`                                                      |
| `locale`          | `en` \| `fr`, optional | message language; default = detected CV language, else `en`                                       |
| `include_text`    | bool, optional         | return the extracted text (`document.text`, ≤ 100 000 chars); default `false`                     |

Errors (Phase 2 envelope): `422` validation (both/neither of `file`/`cv_text`, bad sizes, unsupported type, password-protected, corrupt/unreadable file → `errors.file`), `401`, `429`, `503`-never (no external calls).

### 5.2 Response (`200`) — TypeScript contract

```ts
type AtsReport = {
  version: "ats-2.0"; // scoring-model version; changes only via changelog
  mode: "document" | "job_match";
  score_status: "scored" | "insufficient_text" | "unreadable";
  score: number | null; // integer 0–100, after caps; null unless "scored"
  raw_score: number | null; // before caps
  grade: "strong" | "good" | "needs_work" | "poor" | null; // >=85, 70–84, 50–69, <50
  summary: string; // one sentence, localized
  locale: "en" | "fr";
  language: { detected: "en" | "fr" | "other"; supported: boolean };
  document: {
    source: "file" | "text";
    file_name: string | null;
    type: "pdf" | "docx" | "text";
    size_bytes: number | null;
    pages: number | null;
    word_count: number;
    text_extractable: boolean;
    structure_inspected: boolean; // false for pasted text
    text?: string; // only when include_text=true
  };
  categories: Category[]; // fixed order: format, sections, content, keywords?
  keywords: KeywordReport | null; // null when mode = "document"
  sections: SectionsReport;
  formatting: FormattingReport;
  caps: { id: CapId; limit: number; applied: boolean; reason: string }[]; // only triggered caps; applied = binding (limit < raw_score)
  suggestions: Suggestion[]; // ranked by impact_points desc
  limitations: string[];
  generated_at: string; // ISO-8601 UTC
};

type Category = {
  id: "format" | "sections" | "content" | "keywords";
  title: string;
  earned: number; // sum of applicable checks' earned
  max: number; // sum of applicable checks' max (unverified checks excluded)
  checks: Check[];
};

type Check = {
  id: CheckId; // §6 table
  title: string;
  severity: "blocker" | "major" | "minor";
  status: "pass" | "fail" | "unverified";
  earned: number; // 0 for fail/unverified
  max: number; // defined in §6 (for keyword_coverage: 30)
  applicable: boolean; // false when unverified → excluded from score
  confidence: "high" | "medium" | "low";
  finding: string; // what was observed
  action: string | null; // what to do (null on pass)
  evidence: string[]; // ≤ 3 exact lines/snippets, each ≤ 200 chars
};

type KeywordReport = {
  status: "ok" | "insufficient_job_description";
  coverage: number | null; // weighted, 0–1, 3 decimals
  required: { matched: number; total: number };
  preferred: { matched: number; total: number };
  items: KeywordItem[]; // ≤ 40, sorted: required missing, preferred missing, matched
  stuffing: { term: string; count: number }[];
};

type KeywordItem = {
  term: string; // canonical term from the job description
  kind: "required" | "preferred";
  weight: 2 | 1;
  status: "matched" | "missing";
  match_type: "exact" | "synonym" | "stem" | null;
  matched_as: string | null; // the CV wording that matched
  found_in: ("experience" | "skills" | "education" | "other")[];
  evidence: string[]; // ≤ 2 CV lines
  job_context: string | null; // the JD line the term came from
};

type SectionsReport = {
  contact: { email: string | null; phone: string | null }; // detected values, for display
  experience: SectionFound;
  education: SectionFound;
  skills: SectionFound;
};
type SectionFound = {
  found: boolean;
  heading: string | null;
  line: number | null;
};

type FormattingReport = {
  columns: Detection;
  tables: Detection;
  images: Detection & { count: number; largest_area_pct: number | null };
  text_boxes: Detection;
  header_footer: Detection & { contact_only_there: boolean };
  glyph_issues: Detection;
};
type Detection = {
  detected: boolean | null; // null = not inspected
  confidence: "high" | "medium" | "low" | null;
  note: string | null;
};

type Suggestion = {
  id: string; // stable: "<check_id>" or "keyword:<term>" (term lower-cased, spaces kept: "keyword:content strategy")
  rank: number; // 1-based
  severity: "blocker" | "major" | "minor" | "info";
  category: Category["id"];
  check_id:
    | CheckId
    | "keyword_missing"
    | "keyword_skills_only"
    | "keyword_stuffing";
  title: string;
  detail: string; // why it matters
  action: string; // concrete fix; keyword suggestions say "add only if true for you"
  impact_points: number | null; // score gain if this alone is fixed (what-if recompute, caps included); null only when score is null
  evidence: string[];
};

type CapId = "major_format_issue" | "no_email" | "no_experience";
type CheckId =
  | "readable_text"
  | "single_column"
  | "layout_tables"
  | "images"
  | "text_boxes_headers"
  | "file_supported"
  | "clean_characters"
  | "email"
  | "phone"
  | "experience_section"
  | "education_section"
  | "skills_section"
  | "dates"
  | "action_verbs"
  | "quantified_results"
  | "length"
  | "no_duplicates"
  | "keyword_coverage";
```

Stability rules: field names and enums above are the contract; additions are allowed, removals/renames bump `version` to `ats-3.0` and the route version. A JSON Schema (`docs/ats-report.schema.json`) is generated from these types and enforced by a contract test; the UI types are derived from it.

### 5.3 Example (two-column PDF + job description; abbreviated)

```json
{
  "version": "ats-2.0",
  "mode": "job_match",
  "score_status": "scored",
  "score": 84,
  "raw_score": 87,
  "grade": "good",
  "summary": "Good content, but the two-column layout can scramble text in many ATS parsers.",
  "locale": "en",
  "language": { "detected": "en", "supported": true },
  "document": {
    "source": "file",
    "file_name": "cv.pdf",
    "type": "pdf",
    "size_bytes": 84211,
    "pages": 2,
    "word_count": 512,
    "text_extractable": true,
    "structure_inspected": true
  },
  "categories": [
    {
      "id": "format",
      "title": "Format and parsing",
      "earned": 24,
      "max": 30,
      "checks": [
        {
          "id": "single_column",
          "title": "Single-column reading order",
          "severity": "major",
          "status": "fail",
          "earned": 0,
          "max": 6,
          "applicable": true,
          "confidence": "medium",
          "finding": "Two text columns were detected on page 1 and page 2.",
          "action": "Move your skills and contact details into the main column, or use a single-column template.",
          "evidence": [
            "Left column: 'SKILLS', 'PHP', 'Laravel' …",
            "Right column: 'EXPERIENCE' …"
          ]
        }
      ]
    },
    {
      "id": "keywords",
      "title": "Job keywords",
      "earned": 23,
      "max": 30,
      "checks": []
    }
  ],
  "keywords": {
    "status": "ok",
    "coverage": 0.75,
    "required": { "matched": 5, "total": 6 },
    "preferred": { "matched": 2, "total": 4 },
    "items": [
      {
        "term": "Redis",
        "kind": "required",
        "weight": 2,
        "status": "missing",
        "match_type": null,
        "matched_as": null,
        "found_in": [],
        "evidence": [],
        "job_context": "Experience with Redis caching"
      },
      {
        "term": "CI/CD",
        "kind": "required",
        "weight": 2,
        "status": "matched",
        "match_type": "synonym",
        "matched_as": "continuous integration",
        "found_in": ["experience"],
        "evidence": ["Set up continuous integration with GitHub Actions"],
        "job_context": "CI/CD pipelines"
      }
    ],
    "stuffing": []
  },
  "sections": {
    "contact": { "email": "a@example.com", "phone": "+212 600 000 000" },
    "experience": { "found": true, "heading": "Work Experience", "line": 14 },
    "education": { "found": true, "heading": "Education", "line": 41 },
    "skills": { "found": true, "heading": "Skills", "line": 3 }
  },
  "formatting": {
    "columns": {
      "detected": true,
      "confidence": "medium",
      "note": "2 columns on 2 pages"
    },
    "tables": { "detected": false, "confidence": "medium", "note": null },
    "images": {
      "detected": false,
      "confidence": "high",
      "note": null,
      "count": 0,
      "largest_area_pct": null
    },
    "text_boxes": {
      "detected": null,
      "confidence": null,
      "note": "Not applicable to PDF"
    },
    "header_footer": {
      "detected": false,
      "confidence": "medium",
      "note": null,
      "contact_only_there": false
    },
    "glyph_issues": { "detected": false, "confidence": "high", "note": null }
  },
  "caps": [
    {
      "id": "major_format_issue",
      "limit": 84,
      "applied": true,
      "reason": "A major formatting check failed (single_column)."
    }
  ],
  "suggestions": [
    {
      "id": "single_column",
      "rank": 1,
      "severity": "major",
      "category": "format",
      "check_id": "single_column",
      "title": "Use a single-column layout",
      "detail": "Many ATS read columns line by line, mixing sidebar and experience text.",
      "action": "Move your skills and contact details into the main column, or use a single-column template.",
      "impact_points": 9,
      "evidence": []
    },
    {
      "id": "keyword:redis",
      "rank": 2,
      "severity": "minor",
      "category": "keywords",
      "check_id": "keyword_missing",
      "title": "Missing required keyword: Redis",
      "detail": "The job lists Redis as required and it does not appear in your CV.",
      "action": "If you have real Redis experience, add it to a relevant bullet or your skills. Do not add skills you do not have.",
      "impact_points": 0,
      "evidence": []
    }
  ],
  "limitations": [
    "This is an estimate of document quality and keyword coverage, not the result of any employer's ATS.",
    "Layout detection in PDFs is heuristic; confidence is shown per finding.",
    "Scanned documents are not OCR'd."
  ],
  "generated_at": "2026-10-01T10:00:00Z"
}
```

(`impact_points` for `single_column` is 9: with that check passing the raw score would be 93 and no cap would apply, so the score goes 84 → 93. Fixing the Redis gap alone is 0 because the cap of 84 is still binding. These figures follow rule R5 exactly and are pinned by fixture F4b in §8.2.)

## 6. Scoring model (`ats-2.0`)

Points are fixed constants in `config/ats.php`; every check is **pass/fail** (no partial credit) except `keyword_coverage`.

| Cat        | Check id             | Severity | Max | Passes when                                                                         |
| ---------- | -------------------- | -------- | --: | ----------------------------------------------------------------------------------- |
| A format   | `readable_text`      | blocker  |   6 | text extractable, ≥ 40 words, no replacement characters in > 1 % of chars           |
| A          | `single_column`      | major    |   6 | no multi-column layout (§4.1)                                                       |
| A          | `layout_tables`      | major    |   5 | no table containing text                                                            |
| A          | `images`             | major    |   5 | no image ≥ 15 % of page area and ≤ 2 images total (small photos allowed)            |
| A          | `text_boxes_headers` | minor    |   3 | no content in text boxes; email/phone appear in the body, not only in header/footer |
| A          | `file_supported`     | minor    |   3 | PDF/DOCX and ≤ 5 MB                                                                 |
| A          | `clean_characters`   | minor    |   2 | no private-use/icon glyphs, no letter-spaced headings                               |
| B sections | `email`              | major    |   5 | valid email found                                                                   |
| B          | `phone`              | minor    |   3 | phone number found                                                                  |
| B          | `experience_section` | major    |   6 | recognized experience/work/projects heading (EN/FR) followed by ≥ 1 entry           |
| B          | `education_section`  | minor    |   4 | recognized education heading + ≥ 1 line                                             |
| B          | `skills_section`     | minor    |   4 | recognized skills heading + ≥ 1 line                                                |
| B          | `dates`              | minor    |   3 | ≥ 2 parseable date ranges in the experience section, one consistent style           |
| C content  | `action_verbs`       | minor    |   5 | ≥ 60 % of experience bullets start with an action verb (EN/FR lists)                |
| C          | `quantified_results` | minor    |   5 | ≥ 2 bullets with a number, %, or currency result                                    |
| C          | `length`             | minor    |   3 | 250–1 000 words                                                                     |
| C          | `no_duplicates`      | minor    |   2 | no duplicated bullets                                                               |
| D keywords | `keyword_coverage`   | minor    |  30 | continuous: `round(30 × coverage)`                                                  |

Existing check logic (headings lists, action verbs, contribution heuristics) in `AtsDocumentReview` is **ported into the check classes**, not rewritten from scratch, so vocabulary coverage does not regress.

**Check rules as built (S2).** Where the table leaves room, the checks apply these rules:

- **Structure checks** (`single_column`, `layout_tables`, `images`, `text_boxes_headers`, `clean_characters`) are `unverified` for pasted text and for low-confidence detections (PDF tables). `images`: when the image area is unknown, it is judged on the count only. `text_boxes_headers` on a PDF judges the header/footer part only (text repeated on every page); a one-page PDF has no detectable header/footer. `file_supported` is `unverified` for pasted text.
- **`clean_characters`:** the same private-use glyph starting ≥ 3 lines is a symbol-font bullet, not an icon.
- **`phone`:** 8–15 digits, international or local format, not a year range or a date.
- **Sections:** headings are recognised case- and accent-insensitively, with plurals, numbering, a trailing colon and letter-spacing (`S K I L L S`), ≤ 60 characters and ≤ 5 words; vocabulary in `resources/ats/headings.{en,fr}.json`, ported from `AtsDocumentReview`.
- **`dates`:** style classes are `Mon YYYY` (EN/FR month names or abbreviations), `MM/YYYY` and `YYYY`; Present/Current/aujourd'hui/présent/en cours are valid ends; mixing classes fails.
- **Bullets** are experience-section lines starting with a list marker (`•`, `-`, `*`, `▪`, `–`, `►`, `✓`, `1.`, a symbol-font bullet); wrapped PDF lines are joined. Without any marker, lines of ≥ 6 words that are not date lines count (Canva draws markers as shapes).
- **`action_verbs`:** French action nouns ("Développement de…", "Mise en place…") count as action verbs, as in the legacy engine.
- **`quantified_results`:** years and date ranges are not results.

**Rules**

- **R1 Normalization.** `score = round_half_up(100 × Σ earned / Σ max)` over **applicable** checks only. Without a job description `keyword_coverage` is absent (max 70 in full-file mode). Unverified checks (pasted text, low-confidence detections) are excluded from both sums, never counted as passes.
- **R2 Coverage.** `coverage = Σ weight(matched) / Σ weight(all)`, weight 2 for required, 1 for preferred. If fewer than 3 keywords can be extracted from the job description → `keywords.status = "insufficient_job_description"`, `keyword_coverage` not applicable, and a suggestion asks for a fuller description.
- **R3 Caps** (applied after normalization, lowest wins): any failed `major` **format** check (`single_column`, `layout_tables`, `images`) → **84**; `email` fails → **79**; `experience_section` fails → **74**. Only **triggered** caps appear in `caps[]`, each with `applied` = whether it is binding (`limit < raw_score`); a report with no failed cap condition has `caps: []`.
- **R4 Status.** `readable_text` fails or no extractable text → `score_status: "unreadable"`, `score: null`; fewer than 40 words → `"insufficient_text"`, `score: null`. Checks and suggestions are still returned. For `unreadable`, every check other than `readable_text` is `unverified`, and suggestions carry `impact_points: null`.
- **R5 Impact.** For each failed/missing item, `impact_points` = `score(with that one check passing, other results unchanged, caps re-evaluated) − current score`, ≥ 0. Suggestions sort by `impact_points` desc, then severity (`blocker > major > minor > info`), then `id`. The UI can therefore promise exact gains.
- **R6 Keyword suggestions:** one suggestion per missing **required** keyword (max 5, weight-ordered), one suggestion per missing **preferred** keyword (max 3, ordered by impact, then id; severity `info`, so a missing required keyword, severity `minor`, ranks first on equal impact, as in §5.3), one `keyword_skills_only` per term found only in the skills section (recommend using it in an experience bullet), one `keyword_stuffing` (info) per term with > 10 occurrences (stuffing never changes the score).
- **R7 Determinism.** No randomness, no clock in scoring, no network; the same input produces an identical report except `generated_at`.
- **R8 Grade:** `strong ≥ 85`, `good 70–84`, `needs_work 50–69`, `poor < 50`.

### 6.1 Keyword extraction (deterministic, as built in S2)

1. Normalize the job description (NFKC, typographic punctuation made plain); comparisons use the folded form (lower case, no accents), terms keep the job description's wording.
2. **Blocks:** a short line (≤ 6 words) naming requirements opens a _required_ block (`requirements`, `qualifications`, `must have`, `required`, `what you'll need`, `what we're looking for`, `skills`, `profil recherché`, `votre profil`, `compétences (requises)`, `exigences`, `vous avez`, `prérequis`…), one naming extras opens a _preferred_ block (`nice to have`, `preferred`, `bonus`, `a plus`, `un plus`, `souhaité`, `atout`, `apprécié`, `optional`…); preferred wins when both appear ("Preferred qualifications"). With a colon the label only has to contain those words, and text after the colon is read as items; without a colon the whole line must be the heading phrase, so an item such as "Communication skills" stays an item. A block ends at any other short line ending with ":", or at a line without a list marker after a blank line once the block has items ("What we offer").
3. **Candidates (rule b′):**
   - inside a block, each **list item** (a line, or a part split on `,` `;` `•` `|` and, unless the part is a taxonomy phrase, on "and/et/&"; text in parentheses is a list of its own) of **≤ 4 words**, after removing the list marker, filler ("Experience with", "Strong knowledge of", "Bonne maîtrise de", "… experience") and trailing punctuation, is one keyword **as written**. An item that contains taxonomy phrases without being one gives those phrases instead ("Experience with Redis caching" → Redis). Longer items give their taxonomy phrases only;
   - **outside blocks, only taxonomy phrases** from skills with `anywhere: true` (not ambiguous words, not 1–2-letter words); no free n-grams.
   Taxonomy: `resources/ats/skills.json` (curated from the `AtsScorer` lexicon; non-synonym legacy groups split) plus extra groups in `synonyms.{en,fr}.json`; groups sharing a phrase are merged; both files are always loaded (the job description and the CV can differ in language).
4. **Kind/weight:** in a required block → required (2); in a preferred block → preferred (1); outside blocks, ≥ 2 occurrences of the term or its synonyms → required, else preferred. One keyword per taxonomy group: block wording and kind win over a free-text hit, and required wins over preferred.
5. Drop generic items (blocklist `resources/ats/keyword-blocklist.{en,fr}.txt`: `experience`, `team`, `skills`, `years`, `équipe`, `compétences`…; stop words only; "3+ years"; no letters); keep at most 40 by (weight, frequency, taxonomy term, order).

### 6.2 Matching

For each keyword, in order: **exact** (normalized token sequence) → **synonym** (same synonym group in `resources/ats/synonyms.{en,fr}.json`, e.g. `js↔javascript`, `k8s↔kubernetes`, `ci/cd↔continuous integration`, `gestion de projet↔project management`) → **stem** (Snowball stems of the token sequence equal, same order, stop words ignored). Matching respects token boundaries (`Java` ≠ `JavaScript`, `go` ≠ `going`). **Terms of 3 letters or less and technical tokens (`c#`, `node.js`, `ci/cd`, `vue3`) are never stem-matched** (exact and synonym only): Snowball stems "going" to "go" (verified in S1), so stemming them would break the §8.3 `Go` row. Evidence is the CV line(s) containing the match (≤ 2, ≤ 200 chars); `found_in` comes from the section the line sits in.

As built in S2: tokens keep tech forms whole (`c#`, `.net`, `node.js`, `ci/cd`); a slash token is split into its parts unless every part has ≤ 3 characters (`docker/kubernetes` → two tokens, `ci/cd`, `a/b`, `ui/ux` stay whole). **Guarded words** only count when written with a capital, in the job description and in the CV: single words of 1–2 letters (`Go`, `JS`, `AI`, `ML`) and the phrases listed as `capitalised` in `skills.json` (`Vue` vs "en vue de", `Tableau` vs "tableau de bord", `Spring`, `Rust`, `Flask`, `Sketch`). Stem matching runs only for CVs detected as English or French. Evidence lists experience lines first, then skills, education, other; `matched_as` is the CV wording of the first such match ("gestion de projets"). Stuffing counts the term and its synonyms (> 10 → reported).

## 7. Project structure

```text
app/Services/Ats/
  AtsAnalyzer.php                 # orchestrator
  Parsing/   DocumentReader (entry), DocumentTypeDetector, DocumentParser (interface), PdfParser, DocxParser, TextParser,
             Poppler (process wrapper), GlyphInspector, ParsedDocument, Line, Structure, Detection, Confidence, UnreadableDocument
  Language/  LanguageDetector, Normalizer, Tokenizer, StopWords, Stemmer
  Keywords/  Taxonomy, Tokens, KeywordExtractor, JobKeyword, CvIndex, KeywordMatcher, KeywordMatch, StuffingDetector,
             KeywordReport, KeywordAnalyzer
  Sections/  SectionDetector, Sections, DateRanges
  Checks/    Check.php (interface), CheckResult, CheckStatus, CheckContext, CheckRunner, Format/*, Sections/*, Content/*
  Scoring/   ScoreCalculator, Caps, Grade, WhatIf, SuggestionBuilder, MessageCatalog
  Report/    AtsReport (DTO), AtsReportResource
app/Http/Controllers/Api/V1/Ats/AnalysisController.php
app/Http/Requests/Ats/StoreAtsAnalysisRequest.php
config/ats.php                    # weights, thresholds, version
resources/ats/                    # skills.json, synonyms.en|fr.json, keyword-blocklist.*, stopwords.*, action-verbs.*, action-nouns.fr, headings.*
lang/{en,fr}/ats.php              # findings, actions, titles, summaries
docs/ats-report.schema.json       # JSON Schema of §5.2 (contract test source)
docs/ats-scoring.md               # model, weights, changelog per version
tests/Unit/Ats/…  tests/Feature/Ats/…
tests/fixtures/ats/{cvs,jobs,expected}/…  # §8
tests/fixtures/ats/build.php      # regenerates DOCX/PDF fixtures
```

## 8. Test cases with expected scores

Fixtures live in `tests/fixtures/ats/`. DOCX are built with PhpWord (existing dependency); PDFs with FPDF (`setasign/fpdf`, **dev** dependency, §17 answer 2) for exact column/table/image placement; the generated files are committed together with `build.php`. Base content **BASE-EN**: name, email, phone, Experience (3 roles, date ranges `MMM YYYY – MMM YYYY`, 10 bullets starting with action verbs, 4 with quantified results), Education, Skills, ~480 words, single column, no images. All numbers below follow §6 exactly (`Σ earned / Σ max`, round half up, then caps).

### 8.1 Document mode (no job description; applicable max 70 for files, 48 for pasted text)

| #   | Fixture                             | What is different from BASE-EN                                                   | Failing checks                                                                                     |         Raw | Caps                                            |                **Score** | Grade  | Top suggestion (impact)                                            |
| --- | ----------------------------------- | -------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- | ----------: | ----------------------------------------------- | -----------------------: | ------ | ------------------------------------------------------------------ |
| F1  | `clean-en.docx`                     | none                                                                             | —                                                                                                  | 70/70 = 100 | —                                               |                  **100** | strong | —                                                                  |
| F2  | `clean-en.pdf`                      | same content as PDF                                                              | —                                                                                                  |         100 | —                                               |                  **100** | strong | —                                                                  |
| F4  | `two-column.pdf`                    | skills + contact in a left sidebar                                               | `single_column` (6)                                                                                |  64/70 = 91 | `major_format_issue` 84                         |                   **84** | good   | use single column (+16)                                            |
| F5  | `table-layout.docx`                 | whole CV laid out in a 2-column table                                            | `layout_tables` (5)                                                                                |  65/70 = 93 | `major_format_issue` 84                         |                   **84** | good   | remove layout table (+16)                                          |
| F6  | `scanned.pdf`                       | only an embedded page image, no text                                             | `readable_text`                                                                                    |           — | —                                               | **`null`**, `unreadable` | null   | export a text PDF or run OCR first                                 |
| F7  | `photo-icons.docx`                  | 4 cm profile photo, phone/email with icon-font glyphs                            | `clean_characters` (2)                                                                             |  68/70 = 97 | —                                               |                   **97** | strong | replace icon glyphs with text (+3)                                 |
| F8  | `missing-sections.docx`             | no Education, no Skills, no phone                                                | `phone` 3, `education_section` 4, `skills_section` 4                                               |  59/70 = 84 | —                                               |                   **84** | good   | add Education (+6) · Skills (+6) · phone (+5)                      |
| F9  | `no-email-no-exp.txt` (pasted text) | 150 words, phone, Education, Skills; no email, no experience heading, no bullets | `email` 5, `experience_section` 6, `dates` 3, `action_verbs` 5, `quantified_results` 5, `length` 3 |  21/48 = 44 | `no_email` 79, `no_experience` 74 (not binding) |                   **44** | poor   | add an experience section (+12) · email (+10) · action verbs (+10) |
| F12 | `too-long.docx`                     | ~1 400 words, 4 pages                                                            | `length` (3)                                                                                       |  67/70 = 96 | —                                               |                   **96** | strong | shorten to ≤ 1 000 words (+4)                                      |

Impact values are the R5 what-if results, each computed by forcing only that check to pass and re-evaluating caps. Worked examples: F4 `single_column` raw 64→70, no cap → 100 (+16); F8 phone raw 59→62 → 62/70 = 88.57 → 89 (+5); F9 `experience_section` 21→27 of 48 → 56.25 → 56, `no_email` cap 79 not binding (+12); F9 `email` 26/48 → 54 (+10).

F9 also asserts: `structure_inspected=false`; `single_column`, `layout_tables`, `images`, `text_boxes_headers`, `file_supported` are `unverified` and `applicable=false`; `readable_text` and `clean_characters` are evaluated.

### 8.2 Job-match mode (full scale 100 = 30 format + 25 sections + 15 content + 30 keywords)

| #   | Fixture                                                                             | Job description (fixture `jobs/…`)                                                                                                                                                      | Keyword result                                                                                                                                                                                                                                                                                 |                     Raw | Caps                                                                 |                                                                                                                                 **Score** |
| --- | ----------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------: | -------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------: |
| F3  | `clean-en.docx` (+ PHP/Laravel content)                                             | `laravel-dev.txt`: _Requirements:_ PHP, Laravel, MySQL, REST APIs, Docker, Git, PHPUnit, CI/CD, Redis, AWS. _Nice to have:_ Vue.js, Kubernetes, GraphQL, Terraform                      | Required matched 8/10: exact PHP, Laravel, MySQL, Docker, PHPUnit; synonym `REST APIs` ← "RESTful API", `Git` ← "GitHub", `CI/CD` ← "continuous integration"; missing Redis, AWS. Preferred 1/4: synonym `Vue.js` ← "Vue". Coverage `(16+1)/(20+4) = 0.708` → D = `round(30 × 0.708)` = **21** |            70 + 21 = 91 | —                                                                    | **91** (strong). Top suggestions: `keyword:aws` (+3), `keyword:redis` (+3), then preferred `kubernetes`, `graphql`, `terraform` (+2 each) |
| F13 | `clean-en.docx`                                                                     | `unrelated-marketing.txt`: _Requirements:_ SEO, Google Analytics, content strategy, copywriting, email marketing. _Nice to have:_ HubSpot, A/B testing, Photoshop (weights 10 + 3 = 13) | 0 matched → D = 0                                                                                                                                                                                                                                                                              |                      70 | —                                                                    |                                 **70** (good). Each missing required keyword `+5` (coverage 2/13 → D 5), each preferred `+2` (1/13 → D 2) |
| F11 | `clean-fr.docx` (headings _Expérience professionnelle_, _Formation_, _Compétences_) | `dev-symfony-fr.txt`: _Profil recherché:_ PHP, Symfony, MySQL, Docker, gestion de projet, tests unitaires. _Atout:_ Vue.js                                                              | Required 6/6 (exact + stem: "gestion de projets" ≈ "gestion de projet"; "tests unitaires"); preferred 0/1. Coverage `12/13 = 0.923` → D = **28**                                                                                                                                               |                      98 | —                                                                    |                                                                **98** (strong), messages in French; only suggestion `keyword:vue.js` (+2) |
| F4b | `two-column.pdf`                                                                    | `laravel-dev-short.txt`: 6 required (weight 12) + 4 preferred (weight 4); CV matches 5 required + 2 preferred → coverage `12/16 = 0.75` → D = `round(22.5)` = **23**                    | format 24 + sections 25 + content 15 + keywords 23 = 87                                                                                                                                                                                                                                        | `major_format_issue` 84 | **84** (the example in §5.3; `single_column` +9, `keyword:redis` +0) |

### 8.3 Matcher unit cases (`KeywordMatcherTest`, one assertion per row)

| Job term          | CV text                                   | Expected `match_type`                                |
| ----------------- | ----------------------------------------- | ---------------------------------------------------- |
| JavaScript        | "built UIs in JS"                         | `synonym`                                            |
| Kubernetes        | "deployed services on k8s"                | `synonym`                                            |
| CI/CD             | "set up continuous integration pipelines" | `synonym`                                            |
| Node.js           | "REST services in Node"                   | `synonym`                                            |
| C#                | "backend in C# and .NET"                  | `exact`                                              |
| managing teams    | "managed a team of 6"                     | `stem`                                               |
| testing           | "tested payment APIs"                     | `stem`                                               |
| gestion de projet | "gestion de projets agiles"               | `stem`                                               |
| développement     | "développé une API"                       | `stem`                                               |
| React             | "reactive programming with RxJS"          | **no match** (stem `reactiv` ≠ `react`)              |
| Java              | "JavaScript developer"                    | **no match** (token boundary)                        |
| Go                | "going to the office daily"               | **no match**                                         |
| SQL               | "PostgreSQL administration"               | **no match** (related-term matching is out of scope) |

Stem expectations are verified against the chosen Snowball implementation in S1; any row the library contradicts is fixed in the spec, not the test, and listed in the PR.

### 8.4 Other tests

- **Stuffing:** `clean-en.docx` with "Laravel" repeated 15 times → `keywords.stuffing` contains it, an `info` suggestion exists, **score unchanged** vs. without repetition.
- **Section detection table test:** EN/FR headings (`Work Experience`, `Expérience professionnelle`, `Projects`, `Formations`, `Compétences techniques`, `S K I L L S`) → found/not found.
- **Error cases:** encrypted PDF, corrupt PDF, `.doc`, renamed `.exe`, 16 MB file, both/neither of `file`/`cv_text` → `422` with `errors.file` / `errors.cv_text`; no stack traces.
- **Contract test:** every fixture response validates against `docs/ats-report.schema.json`; `impact_points` ≥ 0; ranks are 1..n without gaps; `Σ earned`/`Σ max` of applicable checks reproduces `raw_score`.
- **Determinism:** same request twice → identical bodies except `generated_at`.
- **Privacy:** after a request, no file remains in the temp dir or `storage/`; no uploaded text appears in logs (tested with a sentinel string).
- **Performance budget:** F1/F2 analysis ≤ 1.5 s; 15 MB worst-case fixture ≤ 10 s in CI (no AI/network involved).
- **Regression of reused vocabulary:** the EN/FR fixtures from `tests/legacy/ats-document.php` (incl. the `FORMATIONS`, letter-spaced `D É V E L O P P E U R` cases) are ported as check-level tests.

## 9. Commands

```bash
composer install
composer test                     # PHPUnit (Phase 2 harness)
composer test -- --filter=Ats     # ATS suites only
php tests/fixtures/ats/build.php  # regenerate DOCX/PDF fixtures
vendor/bin/pint --test && composer audit
php artisan route:list --path=api/v1/ats
curl -s -b cookies -H "X-CSRF-TOKEN: $T" \
  -F file=@tests/fixtures/ats/cvs/clean-en.docx \
  -F job_description="$(cat tests/fixtures/ats/jobs/laravel-dev.txt)" \
  http://localhost:8000/api/v1/ats/analyses | jq '.score,.suggestions[0]'
```

## 10. Code style

PSR-12 via Pint, `final` classes, typed DTOs, no static state, no logic in controllers; checks are small classes returning a `CheckResult`; all user-facing strings come from `lang/*/ats.php`, never inline.

```php
final class SingleColumnCheck implements Check
{
    public function id(): string { return 'single_column'; }

    public function run(ParsedDocument $doc, Context $ctx): CheckResult
    {
        if (! $doc->structure->inspected) {
            return CheckResult::unverified($this->id());
        }

        $columns = $doc->structure->columns;

        if ($columns->confidence->isLow()) {
            return CheckResult::unverified($this->id(), confidence: $columns->confidence);
        }

        return $columns->detected
            ? CheckResult::fail($this->id(), evidence: $columns->samples)
            : CheckResult::pass($this->id());
    }
}
```

## 11. Testing strategy

- **Unit** per module (`tests/Unit/Ats/<Module>`): parsers with tiny generated files, normalizer/tokenizer/stemmer tables, extractor/matcher tables (§8.3), each check class (pass/fail/unverified), score calculator (R1–R8 incl. caps and what-if).
- **Golden/feature:** every fixture in §8 → `expected/<fixture>.json` (subset pinned: `score`, `raw_score`, `grade`, per-check `status`/`earned`, keyword `status`/`match_type`, `caps`, top-3 suggestion ids and `impact_points`). Changing a golden file requires a scoring-model changelog entry in `docs/ats-scoring.md`.
- **Contract:** JSON Schema validation (§8.4). **Security/privacy:** temp-file cleanup, log sentinel, unsupported/encrypted files, prompt/markup in CV text is treated as data (no AI involved).
- **Calibration (S5, before release):** run the engine on the 20 anonymized real CVs the maintainer provides and review that scores/grades feel right; adjust constants in `config/ats.php` and bump the model changelog. This is the only place numbers may move without a spec change.
- **Coverage:** ≥ 90 % lines on `app/Services/Ats`, every check class has pass and fail tests.
- **Frontend (Phase 4):** component tests from the same fixtures' JSON; manual check on a phone and desktop.

## 12. Risks

| #   | Risk                                          | Mitigation                                                                                                                                                                                                                                           |
| --- | --------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| R1  | PDF column/table detection may be unreliable  | **Resolved in S0/S1:** the spike (`docs/ats-spike-s0.md`) showed smalot cannot see Canva layouts; poppler detects all 9 test PDFs correctly and is in the image since S1. Confidence levels and `unverified` remain the guard against false failures |
| R2  | Stemming/synonym false positives or negatives | Token-boundary matching, curated synonym groups, unit table §8.3, `match_type` shown so users see _why_                                                                                                                                              |
| R3  | Score weights are opinionated                 | Calibration step, versioned model + changelog, weights in config                                                                                                                                                                                     |
| R4  | Large uploads slow the request                | 15 MB cap, time budget tests, `max_execution_time` review                                                                                                                                                                                            |
| R5  | Scope creep into OCR/AI/history               | Explicit non-goals; new items go to Phase 5                                                                                                                                                                                                          |

## 13. What the UI gets (Phase 4, `cv-ai`; for reference)

Report tabs map 1:1 to the response: Overview (`score`, `grade`, `summary`, top 4 `suggestions`), Keywords (`keywords.items` grouped by kind/status with `match_type` badges and evidence), Format (`formatting`, format `checks`), Sections (`sections`, section `checks`), Content (`checks`), All suggestions (sorted by `rank`, each showing `+impact_points`). Re-analysis after edits sends `cv_text` and must warn that format checks become `unverified`; re-uploading the edited file restores them. The AI review (`ai/ats-analysis`) is an on-demand button and is never part of the score.

## 14. Boundaries

**Always:** write the fixture/golden test before the check it covers; keep scoring pure and deterministic; return evidence for every failure; state limitations in the report; delete uploads in `finally`; run `composer test`, `pint --test`, `composer audit` before each commit; small atomic commits.

**Approved (§17):** `wamania/php-stemmer` and `ext-intl` in the Dockerfile; `setasign/fpdf` as a dev dependency; `poppler-utils` in the image (after S0).

**Ask first:** any other dependency; other Docker/PHP extension changes; changing weights, caps or grades outside the S5 calibration; removing `ats/document`, `AtsDocumentReview`, `AtsScorer` or `tests/legacy/ats-document.php` (approved only as the follow-up PR in §17 answer 6); adding languages; persisting uploads or reports; calling AI from the engine; changing any AI prompt (§16.1).

**Never:** claim an employer-ATS result or hiring probability; invent or auto-insert skills the user does not have; let AI output change the score; store, log or send CV content to third parties from this endpoint; return stack traces or internal paths; edit golden files without a changelog entry; skip or delete failing tests to get green; start a slice before the previous slice's PR is merged.

## 15. Success criteria

1. `POST /api/v1/ats/analyses` accepts PDF, DOCX or text (+ optional job description) and returns a report that validates against `docs/ats-report.schema.json` for all fixtures.
2. Every expected score, grade, cap, per-check status and keyword result in §8.1–§8.3 is reproduced exactly (with §8.1's impact figures pinned from the S2 recompute).
3. F4/F5 (columns, tables), F6 (scanned), F7 (icon glyphs/photo) are detected as specified; pasted text marks structure checks `unverified` and excludes them from the score.
4. Keyword matching handles exact, synonym and stem (EN + FR) and the no-match guards in §8.3.
5. Suggestions are ranked by recomputed `impact_points`; for F4, F5, F7 and F8 a corrected variant of the fixture (single column, no layout table, text instead of icon glyphs, Education added) scores exactly current + the advertised impact.
6. Same input → same report; no upload persists; no CV text in logs; p95 ≤ 3 s for ≤ 2-page files.
7. `composer test`, `pint --test` and `composer audit` pass; the legacy `ats/document` endpoint still works until its removal PR (§17 answer 6).

## 16. Delivery slices

S0 spike + fixtures (build.php, F1–F13) · S1 `ats-language` + `ats-parsing` (adds `ext-intl` + `wamania/php-stemmer`) · S2 `ats-checks` + `ats-keywords` · S3 `ats-scoring` (score, caps, what-if, suggestions, EN/FR messages) · S4 `ats-api` (route, request, Resource, JSON Schema, privacy tests) · S5 docs (`ats-scoring.md`, changelog) and calibration on the maintainer's 20 anonymized CVs · S6 prompt envelope (§16.1, evaluation first). Then Phase 4 builds the UI on this API, and a separate PR removes the legacy engine once the UI uses the new one (§17 answer 6).

### 16.1 Carried over from Phase 2 (S4): one prompt envelope for AI calls

Moved here from Phase 2 §7 item 3 (decision after the S4 plan). Delivered as slice S6. Scope: every AI call that interpolates untrusted text (`ai/chat`, `ai/cover-letter`, the career generators, interviews) sends CV, job and user text as a JSON-encoded block labelled as data, never as instructions; prompt _wording_ stays as is. **No prompt changes before a model evaluation:** it changes the text the model receives, so S6 first records before/after outputs on the fixture CVs, and the maintainer accepts the comparison before any prompt is changed. It is a separate slice with its own acceptance check, not part of the deterministic ATS engine. The legacy `ai/ats-analysis` already uses a JSON data block.

## 17. Decisions (approved)

1. **Dependencies/extensions:** add `wamania/php-stemmer` (Snowball EN/FR) and `ext-intl` to the Dockerfile (S1).
2. **PDF fixtures:** `setasign/fpdf` as a **dev** dependency; generated PDFs and `build.php` are committed (S0).
3. **PDF structure accuracy:** start with `smalot/pdfparser` only; **ask the maintainer before adding poppler**. _After S0:_ poppler-utils approved and added in S1; smalot removed.
4. **Score ceiling:** no cap; keep the limitations text.
5. **Weights/caps (§6):** accepted as the starting model; calibrated in S5 on 20 anonymized real CVs the maintainer provides.
6. **Legacy removal** (`ats/document`, `AtsDocumentReview`, `AtsScorer`, `tests/legacy/ats-document.php`): a follow-up PR after the new engine is live and the UI uses it.
7. **Languages:** EN + FR for v1; Arabic later (Phase 5).
8. **AI review:** on-demand, never part of the score.
9. **Persistence:** stateless; history and compare in Phase 5.
10. **Keyword taxonomy:** file-based, versioned in the repo.
11. **Message catalog:** EN/FR; the maintainer reviews the French copy.

Also decided: Phase 3b is skipped (the ATS UI is built in Phase 4), and the prompt envelope (§16.1) needs a model evaluation before it changes any prompt.

**S0 Checkpoint A (fixtures review), approved:**

12. Missing preferred keywords: one suggestion per term, max 3 (R6); this matches the F3 and F11 rows of §8.2. They have severity `info`, which keeps the §5.3 order (`keyword:redis` before the +0 preferred terms) consistent with R5's tie-break.
13. Unreadable documents: other checks `unverified`, `impact_points: null` (R4, §5.2).
14. Keyword suggestion ids keep the lower-cased term with spaces (§5.2).
15. `caps[]` lists only triggered caps (R3, §5.2).
16. §6.1 candidate rule (b) is too broad for job ads written in sentences (every phrase in a requirements block would become a keyword). S2 specifies a tighter rule (taxonomy terms plus short stand-alone lines) before building the extractor; the fixture job descriptions use one term per line.

**S2 plan decisions, approved:**

17. Keyword extraction rule (b′) as in §6.1: list items of ≤ 4 words inside requirement blocks, taxonomy phrases everywhere, no free n-grams outside blocks.
18. Dates "one consistent style" as in the §6 rules as built (`Mon YYYY` / `MM/YYYY` / `YYYY`, open ends allowed).
19. French action nouns count as action verbs.
20. Legacy lexicon groups that are not synonyms (SQL/PostgreSQL, Java/Spring, Docker/containers, ML/AI, REST/apis) are split.
21. Points and messages stay in S3; S2 returns statuses, evidence, message keys and the keyword report. The French vocabulary (headings, action verbs/nouns, taxonomy aliases) is reviewed by the maintainer during S5 calibration.
