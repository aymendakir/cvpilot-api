# Implementation Plan: Phase 3, slice S3 — `ats-scoring`

Spec: `SPEC-ats.md` §5.2 (report contract), §6 (points, R1–R8), §8.1–§8.2 (golden scores), §17. S2 is merged (checks + keyword report). This plan covers **S3 only**.

Branch: `feature/ats-s3` (from `main`). One PR. Stop after opening it.

Delivery order from here (noted in `SPEC-ats.md` §16): **S3 → S4 → Phase 4 (frontend) → S5 calibration and S6 prompt envelope** (after or alongside Phase 4, since neither changes the API contract).

## Overview

S3 turns S2's check results and keyword report into the **scored report**:

- points per check;
- category totals;
- raw score and caps;
- grade and status;
- what-if impact for every suggestion;
- the ranked suggestion list;
- EN/FR text for every title, finding, action, summary, cap reason and limitation.

All of it is pure and deterministic. The clock is injected, so only `generated_at` varies.

S3 ends with an in-process `AtsAnalyzer` that returns the full §5.2 shape. S4 then only adds the HTTP layer: route, FormRequest, Resource, JSON Schema, privacy and error tests.

The golden files already pin everything S3 must produce:

- `score`, `raw_score`, `grade` and `caps`;
- per-check `status` and `earned`;
- the top-3 suggestion ids and `impact_points`.

All 15 cases (F1–F13, F4b, F7/F8 fixed) are reproduced through the real pipeline.

## What I checked before planning

- **Golden figures are consistent with R1–R6.** Some examples:

  | Case | Figures | Why |
  | --- | --- | --- |
  | F4b | 87 raw, capped to 84; `single_column` +9, `keyword:redis` +0 | the cap stays binding |
  | F5 | 93 raw, capped to 84; `layout_tables` +16 | fixing it lifts the cap |
  | F9 | 44, two caps triggered but not binding | both limits are above 44 |
  | F3 | `aws`/`redis` +3 tie, ordered by id; preferred +2 (severity `info`) | R5 tie-break |
  | F13 | three +5 ties, ordered by id | R5 tie-break |
  | F6 | unreadable: `impact_points: null` | R4 |

- **Keyword earned points:** `keyword_coverage` earned is `round_half_up(30 × coverage)`; F4b's 22.5 rounds to 23. The check's status is `fail` whenever coverage < 1 (F3, F4b), as the golden files show.
- **Message keys:** S2 checks return message keys and parameters. The keys are: `ok`, `found`, `missing`, `empty`, `no_text`, `too_short`, `garbled`, `too_many`, `too_large`, `text_boxes`, `contact_in_header`, `glyphs`, `low_confidence`, `not_inspected`, `no_experience_section`, `too_few`, `mixed_styles`, `no_bullets`, `weak_start`, `too_long`, `duplicates`. With the check id they map 1:1 to catalog entries.
- **Translation files:** there is no `lang/` directory yet, so `lang/{en,fr}/ats.php` are new. Laravel's translator loads them with no extra setup.

## Design

```text
config/ats.php            + points/severity per check, caps, grade bands, suggestion limits
app/Services/Ats/
  Scoring/  ScoreCalculator (R1 + categories), Caps (R3), Grade (R8), ScoreResult,
            WhatIf (R5), SuggestionBuilder (R6 + ranking), Suggestion, MessageCatalog
  Report/   AtsReport (DTO, toArray() = §5.2 shape), FormattingReport, SectionsReport
  AtsAnalyzer.php          parse → checks → keywords → score → suggestions → report
lang/en/ats.php, lang/fr/ats.php
```

- **`ScoreCalculator`** is pure. Input: check results and the optional keyword report. Output: per-check `earned`/`max`/`applicable`, category totals, `raw_score`, `score`, `caps` and `score_status`.
- **`WhatIf`** re-runs the calculator with one item flipped. For a check, it passes. For a keyword, it becomes matched and the coverage is recomputed. The impact is `max(0, new score − score)`, caps included. It is null when the score is null.
- **`SuggestionBuilder`** creates one suggestion per failed check and the keyword suggestions (R6). It ranks them by impact, then severity, then id, and numbers the ranks 1..n.
- **`MessageCatalog`** resolves text from the locale and keys with Laravel's translator. It never builds sentences by string concatenation.

### Rules as I will build them (R1–R8 made precise; decisions below)

| Topic | Rule |
| --- | --- |
| Points | §6 table in `config/ats.php`; `pass` earns max, `fail`/`unverified` earn 0; `unverified` → `applicable: false`, excluded from Σ max |
| Raw score | `round_half_up(100 × Σ earned / Σ max)` over applicable checks; categories in fixed order format, sections, content, keywords? |
| Keywords | `earned = round_half_up(30 × coverage)`; `pass` only at coverage 1; with `insufficient_job_description` the check is `unverified` (not applicable) |
| Caps | triggered when the condition holds (failed major format check → 84, `email` fails → 79, `experience_section` fails → 74); `applied` = `limit < raw_score`; score = min(raw, binding limits) |
| Status | no extractable text, or `readable_text` failed for garbled text → `unreadable` (other checks `unverified`); text but < 40 words → `insufficient_text` (checks still run and are shown); both → `score`, `raw_score`, `grade` null, `impact_points` null |
| Grade | strong ≥ 85, good 70–84, needs_work 50–69, poor < 50 |
| Suggestions | one per failed check, with severity from §6; up to 5 missing required keywords (severity `minor`, weight order, then id); up to 3 missing preferred keywords (`info`); `keyword_skills_only` and `keyword_stuffing` (`info`, impact 0) |
| Ranking | `impact_points` desc → severity (`blocker > major > minor > info`) → `id`; `rank` 1..n |
| Locale | request `locale`, else detected CV language when en/fr, else `en` |
| Limitations | always the estimate disclaimer; plus PDF heuristics (PDF), no OCR (when unreadable), pasted text format checks unverified (text), CV language not EN/FR: exact matching only (other) |

## Tasks

### T1 — Points, categories, caps, grade, status

- Add the scoring constants to `config/ats.php`. Build `ScoreCalculator`, `Caps`, `Grade` and `ScoreResult`.
- **Acceptance:**
  - For every golden case: per-check `earned`, `score`, `raw_score`, `grade`, `caps` (id, limit, applied) and `score_status` are reproduced through the real pipeline.
  - Unit tests cover round-half-up edges (x.5) and the status rules above.

### T2 — What-if and suggestions

- Build `WhatIf`, `SuggestionBuilder` and `Suggestion`, and the ranking.
- **Acceptance:**
  - The top-3 suggestion ids and `impact_points` of every golden file are reproduced. This includes F4b `keyword:redis` = 0 (cap binding), F5 `layout_tables` = 16 (cap lifted) and F6 = null.
  - `stuffing.docx` gives the same score as `clean-en.docx` plus one `keyword:laravel` stuffing suggestion with impact 0.
  - Limits hold: at most 5 required and 3 preferred keyword suggestions.
  - Ranks are 1..n with no gaps.

### T3 — EN/FR message catalog

- Write `lang/en/ats.php` and `lang/fr/ats.php` and `MessageCatalog`. They cover:
  - check titles;
  - findings for every (check, key) pair, with the S2 params (`:count`, `:percent`, `:found`…);
  - actions, cap reasons and suggestion titles/details/actions, including the keyword ones ("add only if true for you");
  - summaries per status/grade and the main issue;
  - formatting notes and limitations.
- **Acceptance:**
  - Parity test: the same keys exist in EN and FR with the same placeholders.
  - Every key the checks can emit has an entry.
  - Running all fixtures in both locales gives no raw key and no unreplaced `:placeholder`.
  - F11 is in French.

### Checkpoint A

- [ ] You review the **French and English copy** (§17 item 11) and the scoring rules table above before I wire the report.

### T4 — Report assembly and analyzer

- Build `AtsAnalyzer` and `AtsReport` with the full §5.2 shape:
  - `document`, `language`, `categories`, `keywords` (S2 report), `sections` (contact values, heading, line), `formatting` (detections with notes);
  - `caps`, `suggestions`, `limitations`, `generated_at` (injected clock).
- **Acceptance:**
  - Every fixture produces every §5.2 field with the right types.
  - Determinism: two runs give identical output except `generated_at`.
  - Σ earned / Σ max of applicable checks reproduces `raw_score`.
  - Performance budget: F1/F2 ≤ 1.5 s.

### T5 — Spec, docs, PR, STOP

- Update `SPEC-ats.md` with the rules as built and decisions 22–27, and `tasks/todo.md`.
- Run the full suite, the legacy scripts, `pint --test`, `composer audit` and a fresh-clone check.
- Open the PR and stop.

### Checkpoint B (final)

- [ ] PR opened; S4 (route, request, Resource, JSON Schema, privacy and error tests) planned after merge.

## Risks

| Risk | Mitigation |
| --- | --- |
| What-if under caps confuses users (a +0 keyword) | R5 is exact by design; the copy says the cap is why (cap reason shown in the report); UI explains it in Phase 4 |
| French copy quality | your review at Checkpoint A; vocabulary review again during S5 |
| S5 calibration changes weights after the UI is built | weights are config only; the contract does not change; golden files and the changelog (`docs/ats-scoring.md`) are updated in S5 |
| Too many suggestions on weak CVs | per-type limits (R6) and ranking; the UI shows the top 4 on Overview |

## Decisions needed (recommendation first)

22. **Scope boundary with S4:** S3 includes `AtsAnalyzer` and the full §5.2 report (sections, formatting, summary, limitations), so the golden tests run the complete pipeline in-process. S4 adds only the HTTP layer. *Recommended.*
23. **`unreadable` vs `insufficient_text`:** `unreadable` when there is no extractable text or the text is garbled (replacement characters > 1 %), with every other check `unverified`. `insufficient_text` when the text is readable but under 40 words: the checks still run and are shown, and `score`, `grade` and `impact_points` are null. *Recommended.*
24. **Short job description** (`insufficient_job_description`):
    - `keyword_coverage` is `unverified` (not applicable), so the report is scored like document mode.
    - One suggestion is added: id `keyword_coverage`, check_id `keyword_coverage`, severity `info`, impact 0, "paste the full job description".
    - *Recommended.*
25. **Unverified checks get no suggestion.** Pasted text gets a limitation line ("format checks need the original file") instead. *Recommended.*
26. **`keyword_skills_only`:** up to 3, severity `info`, impact 0, required terms first, then by id. R6 sets no limit, and a long skills list could otherwise flood the list. *Recommended.*
27. **Summary sentence:** chosen from the status, then the top suggestion's check, e.g. "Good content, but the two-column layout can scramble text in many ATS parsers." Otherwise a sentence per grade. *Recommended.*

## Out of scope

The route, FormRequest, Resource, JSON Schema, 422 error cases and privacy tests (S4). Calibration and `docs/ats-scoring.md` (S5). The keyword-source choice (requirement blocks only vs. the whole job ad), which is decided in S5 with real job ads. The prompt envelope (S6). Any UI (Phase 4).
