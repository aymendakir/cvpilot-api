# Implementation Plan: Phase 3, slice S2 — `ats-checks` + `ats-keywords`

Spec: `SPEC-ats.md` §2, §4, §6 (check table, R2, R6), §6.1, §6.2, §8 (fixtures, §8.3, §8.4), §17 items 12–16. S1 is merged and live (poppler parsing, language module). This plan covers **S2 only**.

Branch: `feature/ats-s2` (from `main`). One PR. Stop after opening it.

## Overview

S2 turns a `ParsedDocument` into **check results** (§6 A/B/C: status, confidence, evidence) and a **keyword report** (extraction from the job description, exact/synonym/stem matching, evidence, stuffing). It does **not** compute points, caps, scores, suggestions or message text: that is S3, which reads what S2 returns. Everything stays deterministic and offline.

The S0 golden files already pin what S2 must produce: every expected `checks.*.status` and every keyword item's `status`/`match_type` in `tests/fixtures/ats/expected/*.json`. S2's acceptance test runs the parser + checks + keywords on each fixture and compares against them.

## T0 — `main` is red: land the temp-dir test fix first

PR #24 merged before its CI finished, so `main` still has the `ParsingFixturesTest` check that walks all of `/tmp` and fails on GitHub runners ("Permission denied" on systemd directories). The one-file fix (list only the top level of the temp dir) is already the first commit on this branch (cherry-picked from `fix/ats-temp-dir-scan`, which can then be deleted). No separate PR.

## What I checked before planning

- **Legacy vocabulary** (§6: ported, not rewritten): `AtsDocumentReview` holds the EN/FR heading rules (incl. "FORMATIONS", "Stages et expériences", "Projets techniques") and an action-verb list of ~200 EN/FR verbs; French CVs there also count **action nouns** ("Développement de…", "Création de…", "Mise en place…") as contributions (decision 3).
- **Taxonomy seed:** `AtsScorer::$domainLexicons` (tech, data, sales, health, …) is the seed for `resources/ats/skills.json`. Several of its alias groups are **not synonyms** and contradict §8.3: `SQL ↔ MySQL/PostgreSQL`, `Java ↔ Spring`, `Docker ↔ containers`, `Machine Learning ↔ AI`, `REST APIs ↔ apis`. They are split or dropped in the new files (decision 4). `JobsController` keeps using `AtsScorer` unchanged.
- `tests/legacy/ats-document.php` cases (letter-spaced `D É V E L O P P E U R`, `FORMATIONS`, `COMPÉTENCES`) become check-level tests (§8.4).

## Design

```text
app/Services/Ats/
  Sections/  SectionDetector, Sections (experience/education/skills/other with line ranges, heading, line no.)
  Checks/    Check (interface), CheckResult, CheckContext, CheckRunner,
             Format/  ReadableText, SingleColumn, LayoutTables, Images, TextBoxesHeaders, FileSupported, CleanCharacters
             Sections/ Email, Phone, ExperienceSection, EducationSection, SkillsSection, Dates
             Content/ ActionVerbs, QuantifiedResults, Length, NoDuplicates
  Keywords/  Taxonomy (skills + synonym groups), KeywordExtractor, KeywordMatcher, StuffingDetector,
             JobKeyword, KeywordMatch, KeywordReport
resources/ats/  skills.json, synonyms.en.json, synonyms.fr.json, keyword-blocklist.{en,fr}.txt,
                headings.{en,fr}.json, action-verbs.{en,fr}.txt, action-nouns.fr.txt
```

- **`CheckResult`**: `id`, `status` (`pass|fail|unverified`), `confidence`, `findingKey` + `params` (S3 turns them into EN/FR text), `evidence` (≤ 3 lines, ≤ 200 chars). No points.
- **`CheckRunner`**: runs the 17 document checks in §6 order. R4 at check level: no extractable text → `readable_text` fails and every other check is `unverified` (F6). Structure checks are `unverified` when `structure.inspected` is false (pasted text) or the detection is low confidence.
- **`SectionDetector`**: recognises headings (EN/FR, accent- and case-insensitive, plural forms, letter-spaced, trailing colon) from the ported lists, and assigns every line to a section; used by the section/content checks and by keyword `found_in`.
- **Keyword report** (feeds §5.2 `KeywordReport`): `status` (`ok | insufficient_job_description`), items with `term`, `kind`, `weight`, `status`, `match_type`, `matched_as`, `found_in`, `evidence`, `job_context`, plus `stuffing`. Coverage is computed here (R2); the 30-point conversion is S3.

### Check rules as built (the §6 table, made precise)

| Check | Pass when (S2 precision in italics) |
| --- | --- |
| `readable_text` | text extractable, ≥ 40 words, replacement characters ≤ 1 % of characters |
| `single_column` | no columns detection at ≥ medium; *low → unverified* |
| `layout_tables` | no table detection at ≥ medium (*PDF tables are low → unverified*) |
| `images` | ≤ 2 images and none ≥ 15 % of the page; *area unknown → judged on count only* |
| `text_boxes_headers` | no text boxes, and contact details not only in a header/footer; *PDF: text boxes n/a, header part only* |
| `file_supported` | PDF or DOCX, ≤ 5 MB; *pasted text → unverified* |
| `clean_characters` | glyph detection negative (*symbol-bullet rule from S1 applies*) |
| `email` / `phone` | a valid email / *a phone number of 8–15 digits, international or local format* in the body text |
| `experience_section` / `education_section` / `skills_section` | recognised heading followed by ≥ 1 entry line |
| `dates` | ≥ 2 date ranges in the experience section, *all in one style* (decision 2) |
| `action_verbs` | ≥ 60 % of experience bullets start with an action verb (*or, in French, an action noun*, decision 3) |
| `quantified_results` | ≥ 2 bullets with a number, % or currency amount (*years and date ranges do not count*) |
| `length` | 250–1 000 words |
| `no_duplicates` | no two bullets equal after normalisation |

*Bullets* = experience-section lines that start with a list marker (`•`, `-`, `*`, `▪`, `–`, numbered items, or a symbol-font bullet). If the section has none, lines of ≥ 6 words that are not a role/date line count as bullets (common in Canva exports where markers are drawings).

### Keyword extraction (§6.1 rule (b), tightened — decision 1)

1. Block detection as §6.1 step 2 (EN/FR required/preferred headings).
2. Candidates:
   - **(a)** every taxonomy term or alias found anywhere in the job description;
   - **(b′)** inside a required/preferred block, each **list item** — a line, or a comma/semicolon/"and/et"-separated part of a line — of **≤ 4 words** after removing bullet markers and trailing punctuation, is one candidate term as written ("REST APIs", "Gestion de projet");
   - longer sentences contribute **only** taxonomy hits;
   - outside blocks, **only** taxonomy hits (no free n-grams).
3. Kind/weight as §6.1 step 4; generic blocklist (step 5); at most 40 terms.

This is what the fixture job descriptions already assume (one term per line) and keeps a job ad written in sentences from producing dozens of junk keywords.

### Matching (§6.2 as updated in S1)

exact (normalised token sequence, token boundaries) → synonym (same group in `synonyms.{en,fr}.json`) → stem (Snowball, stop words ignored, same order), with **no stem matching for terms of ≤ 3 letters or technical tokens**. Evidence: ≤ 2 CV lines (≤ 200 chars); `found_in` from `SectionDetector`; `keyword_skills_only` facts for S3 (term found only in Skills); stuffing = > 10 occurrences (never affects coverage).

## Tasks

### T0 — CI fix on `main` (already committed here)

### T1 — Section detection and ported vocabulary
- `headings.{en,fr}.json`, `action-verbs.{en,fr}.txt`, `action-nouns.fr.txt` ported from `AtsDocumentReview`; `SectionDetector`.
- **Acceptance:** §8.4 section table (`Work Experience`, `Expérience professionnelle`, `Projects`, `Formations`, `Compétences techniques`, `S K I L L S` found; prose lines that merely contain those words not found); the legacy `ats-document.php` heading cases; every fixture's sections as the expected files imply.

### T2 — Checks and runner
- The 17 document checks + `CheckRunner` (R4 at check level, `unverified` rules).
- **Acceptance:** pass/fail/unverified unit cases per check; **every `checks.*.status` in `expected/*.json` (F1–F13, F4b, F7/F8 fixed) reproduced** by parsing the fixture and running the checks.

### Checkpoint A
- [ ] Review with maintainer: check rules table, fixture statuses, ported vocabulary.

### T3 — Taxonomy
- `skills.json` (curated from `AtsScorer`), `synonyms.en.json`, `synonyms.fr.json`, blocklists; a validation test (every alias in one group only; §8.3 guards: `sql`/`postgresql`, `java`/`javascript`, `react`/`reactive` never grouped).

### T4 — Keyword extraction
- `KeywordExtractor` with rule (b′); **acceptance:** the four fixture job descriptions give exactly the expected terms and kinds (`expected/F3|F4b|F11|F13.json`), and a realistic sentence-style job ad (new fixture `jobs/sentences-en.txt`) yields only taxonomy terms plus short list items, ≤ 40.

### T5 — Matching, evidence, stuffing
- `KeywordMatcher`, `StuffingDetector`, coverage (R2).
- **Acceptance:** the §8.3 table row by row (including `Go`/"going" → no match); every keyword item's `status`/`match_type` in the four expected files; `stuffing.docx` reports Laravel × 15 and the same coverage as `clean-en.docx`.

### T6 — Spec, PR, STOP
- `SPEC-ats.md` §6/§6.1 updated with the rules as built; `tasks/todo.md`; open the PR.

### Checkpoint B (final)
- [ ] PR opened; S3 (scoring, suggestions, EN/FR messages) planned after merge.

## Risks

| Risk | Mitigation |
| --- | --- |
| Heading and action-verb lists miss real-world wording | ported legacy lists (already tuned on real CVs) + S5 calibration on your 20 CVs |
| Real job ads write requirements as sentences, so rule (b′) finds few keywords | taxonomy hits still apply; the sentence fixture measures it; S5 calibration includes real job ads if you have them |
| Synonym groups too loose (false matches) or too tight | curated groups, §8.3 guards in a test, `match_type` shown to users |
| Bullets without markers (Canva) | fallback rule above, checked on the generated two-column PDF |

## Decisions needed (recommendation first)

1. **Extraction rule (b′)** as written above: list items of ≤ 4 words inside requirement blocks, taxonomy hits everywhere, no free n-grams outside blocks. *Recommended.*
2. **Dates "one consistent style":** style classes are `Mon YYYY` (EN/FR month names or abbreviations), `MM/YYYY`, `YYYY`; "Present/Current/aujourd'hui/présent/en cours" are valid ends. A CV mixing `Mar 2022` and `03/2022` fails; `Mar 2022 – Present` and `Jun 2019 – Feb 2022` pass. *Recommended.*
3. **French action nouns** ("Développement de…", "Création de…", "Mise en place…", "Gestion de…") count like action verbs, as the legacy engine did: it is the normal French CV style. *Recommended.*
4. **Taxonomy curation:** split the non-synonym groups of the legacy lexicon (`SQL`/`PostgreSQL`, `Java`/`Spring`, `Docker`/containers, ML/AI, REST/"apis") into separate skills. *Recommended* (required by §8.3).
5. **Points and messages stay in S3:** S2 returns statuses, evidence and message keys only. *Recommended.*

## Out of scope

Points, caps, score, grade, what-if, suggestions, EN/FR message text (S3); the route and JSON Schema (S4); calibration (S5); the prompt envelope (S6); any UI.
