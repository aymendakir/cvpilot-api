# Implementation Plan: Phase 3, slice S4 — `ats-api`

Spec: `SPEC-ats.md` §5 (request, response, errors), §8.4 (error, contract, determinism, privacy, performance tests), §14 (boundaries). S3 is merged (`AtsAnalyzer` returns the full §5.2 report in-process). This plan covers **S4 only**.

Branch: `feature/ats-s4` (from `main`). One PR. Stop after opening it.

Order from here: **S4 → Phase 4 (frontend) → S5 calibration and S6 prompt envelope** (after or alongside Phase 4; neither changes the API contract).

## Overview

S4 puts the engine behind `POST /api/v1/ats/analyses` and pins the contract:

- the route, the FormRequest and the Resource;
- `docs/ats-report.schema.json`, plus a contract test that validates every fixture response against it;
- the 422 error cases;
- determinism and privacy tests (no file left behind, no CV text in logs, nothing stored);
- the time budget.

The legacy `POST /api/v1/ats/document` and `ai/ats-analysis` stay untouched until the legacy-removal PR (§17 answer 6).

**Included fix (your request after S3):** file sizes in the `file_supported` finding use a decimal comma and "Mo" in French ("0,01 Mo"), and "0.01 MB" in English.

- What main does today: 9 KB gives "0,01 Mo" / "0.01 MB" and 4.8 MB gives "4,8 Mo".
- The gaps: a file under 5 KB reads "0 Mo", and the format depends on a float cast.
- The fix: format the size explicitly, per locale, with two decimals and a minimum of 0.01 for a non-empty file. Tests pin both locales.

## What I checked before planning

- **Conventions to follow (Phase 2):**
  - routes in `routes/api.php` inside the authenticated v1 group;
  - per-user throttles `throttle:N,1,<name>:`;
  - FormRequests extend `ApiFormRequest`;
  - Resources without a `data` wrapper (`JsonResource::withoutWrapping()`);
  - the error envelope `{message, code, errors?, request_id}` from `docs/ERRORS.md`, where clients map `code` and never show `message`;
  - the route contract test plus `docs/ROUTES.md` (regenerated with `UPDATE_ROUTE_DOCS=1`).
- **Upload limits:** the Dockerfile sets `upload_max_filesize=16M` and `post_max_size=20M`. The §5.1 limit is 15 MB, so a 16 MB file is rejected by validation (422). A body over 20 MB is rejected earlier as `413 payload_too_large` (existing behaviour).
- **Unreadable files:** `UnreadableDocument` already carries a reason: `password_protected`, `corrupt`, `unsupported_type` or `timeout`. `DocumentTypeDetector` decides the type from the content: `.doc`, a renamed `.exe` and a `.txt` upload are not PDF/DOCX.
- **No JSON Schema validator** is installed (see decision 28).

## Design

```text
routes/api.php                         POST ats/analyses → Api\V1\Ats\AnalysisController (throttle 20/min/user)
app/Http/Requests/Ats/StoreAtsAnalysisRequest.php
app/Http/Controllers/Api/V1/Ats/AnalysisController.php
app/Http/Resources/AtsReportResource.php   wraps AtsReport::toArray() (no data wrapper)
docs/ats-report.schema.json            JSON Schema (draft 2020-12) of §5.2
docs/ROUTES.md, docs/ERRORS.md         regenerated / ATS file reasons documented
```

### Request (§5.1)

| Field | Rule |
| --- | --- |
| `file` | `file`, ≤ 15 MB; exactly one of `file` / `cv_text` (`required_without` + `prohibits`) |
| `cv_text` | string, 30–30 000 characters |
| `job_description` | optional string, 60–30 000 characters → `mode: "job_match"` |
| `locale` | optional `en` \| `fr` |
| `include_text` | optional boolean |

- The type is decided from the content, after validation, by `DocumentTypeDetector`. A PDF or DOCX passes; anything else (`.doc`, `.exe`, `.txt`, images) → 422 `errors.file`.
- `UnreadableDocument` → 422 `validation_failed` with `errors.file` (decision 29).
- The uploaded file is read in place from PHP's temp upload; nothing is copied. A missing poppler binary stays a 500 (S1 behaviour).

### Response

`200` with the §5.2 body, exactly as `AtsAnalyzer` builds it. The Resource adds nothing and reorders nothing.

## Tasks

### T1 — File size format (requested fix)

- Locale-aware size formatting in `MessageCatalog`:
  - EN: `0.01 MB`, `4.80 MB`;
  - FR: `0,01 Mo`, `4,80 Mo`;
  - two decimals, minimum 0.01 for a non-empty file.
- **Acceptance:** unit tests for 3 KB, 9 KB, 4.8 MB and 12.35 MB in both locales. The S3 fixture text still resolves with no placeholders.

### T2 — Route, request, controller, Resource

- **Acceptance:**
  - A feature test posts every fixture (file, or `cv_text` for F9) with and without a job description, as a signed-in user.
  - The `200` body equals `AtsAnalyzer` output for the same input, except `generated_at`. Golden score, grade, caps and top suggestions hold.
  - `include_text` and `locale` work.
  - `401` when signed out; `429` after 20 requests a minute.
  - `docs/ROUTES.md` is regenerated and the route contract test passes.

### T3 — Error cases (§8.4)

- **Acceptance:** each case gives `422 validation_failed` with the right field in `errors`, and no stack trace, path or class name in the body.
  - `invalid/encrypted.pdf`, `invalid/corrupt.pdf`, `invalid/legacy.doc`, `invalid/renamed-exe.pdf`, a `.txt` upload, and a 16 MB file (generated in the test's temp dir and deleted) → `errors.file`.
  - Both or neither of `file`/`cv_text`; `cv_text` < 30 or > 30 000 characters → `errors.cv_text`.
  - `job_description` < 60 characters → `errors.job_description`.
  - `locale: "de"` → `errors.locale`.
- `docs/ERRORS.md` documents the ATS `errors.file` reasons.

### Checkpoint A

- [ ] You review the request rules, the error-reason format (decision 29) and the schema approach (decision 28) on real responses before the contract and privacy tests.

### T4 — JSON Schema and contract test

- `docs/ats-report.schema.json`, written from the §5.2 types:
  - every enum;
  - integer score from 0 to 100 or null;
  - evidence ≤ 3 items of ≤ 200 characters;
  - keyword items ≤ 40;
  - `additionalProperties: false` on every object, so a stray field fails.
- **Acceptance:**
  - Every fixture response (both modes, both locales, pasted text, unreadable) validates.
  - Ranks are 1..n; `impact_points` is ≥ 0 or null only when `score` is null.
  - Σ earned / Σ max reproduces `raw_score`.
  - A deliberately broken body (missing field, wrong enum) fails validation, so the test can fail.

### T5 — Determinism, privacy, performance

- **Acceptance:**
  - **Determinism:** the same request twice gives identical bodies except `generated_at`.
  - **No files left:** after requests (including failing ones), no new file remains in the temp dir or `storage/`.
  - **No CV text in logs:** a sentinel string in the CV and in the job description never appears in the log output (log channel captured in the test).
  - **Nothing stored:** no row is added to any table.
  - **Time:** F1/F2 ≤ 1.5 s over HTTP; a ~15 MB worst-case PDF (generated at test time, many pages of text plus a large image) ≤ 10 s (§8.4).

### T6 — Spec, docs, PR, STOP

- `SPEC-ats.md` §5 as built (error reasons, rules) and decisions 28–30; `tasks/todo.md`.
- Run the full suite, the legacy scripts, `pint --test`, `composer audit` and a fresh-clone check.
- Open the PR, with a post-merge smoke check for you, and stop.

### Checkpoint B (final)

- [ ] PR opened; Phase 4 (frontend) spec after merge.

## Risks

| Risk | Mitigation |
| --- | --- |
| Big or hostile PDFs slow the request | poppler timeouts (10 s per call, S1) → 422 `timeout`; the 15 MB limit; per-user throttle; the worst-case timing test |
| The schema drifts from the code | the contract test runs on every fixture; `additionalProperties: false`; Phase 4 generates its TS types from the same file |
| CV content leaking into logs | sentinel test; the existing redaction stays; the controller logs nothing about content |
| A frontend cannot tell why a file was refused | stable reason tokens in `errors.file` (decision 29) |

## Decisions needed (recommendation first)

28. **JSON Schema validator:** add `opis/json-schema` (draft 2020-12, maintained, no other dependencies) as a **dev** dependency, used only by the contract test. *Recommended.* Alternative: a small hand-written validator for the subset we use (more code to trust, no new package).
29. **`errors.file` content for unreadable files:**
    - one stable reason token per refusal: `password_protected`, `corrupt`, `unsupported_type`, `timeout`, `too_large`;
    - the frontend maps it to EN/FR text, as `docs/ERRORS.md` already asks clients to do for `code`;
    - Laravel's own messages stay for the other fields;
    - the reasons are documented in `docs/ERRORS.md`.
    *Recommended.*
30. **Analysis log line:** log one line per analysis (status, mode, type, page count, duration in ms, `request_id`), **no** text, file name or score. This gives production visibility without content. *Recommended.* Alternative: no logging.

## Out of scope

The UI (Phase 4); calibration and `docs/ats-scoring.md` (S5); the prompt envelope (S6); the keyword-source choice (S5); removal of the legacy ATS endpoints (separate PR after Phase 4).
