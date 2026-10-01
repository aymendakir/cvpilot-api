# Implementation Plan: Phase 2, slice S3 — validation, Resources, Policies

Spec: `SPEC.md` §5 (validation), §6 (authorization), §11 items 4–5, §18 item 12. This plan covers **S3 only**. S0–S2 are merged. S4–S7 are planned one at a time afterwards.

Branch: `refactor/api-s3-validation` (from `main` after S2). One PR. Stop after opening it.

## Overview

Move every input rule out of controllers into `FormRequest` classes, put owner checks in Policies, and put every response through an API Resource so internal columns stop leaking. Legacy and v1 share controllers, so they get the same validation and the same shapes (§11 items 4–5 apply to both).

S3 does **not** touch: ATS scoring or AI prompts (Phase 3 owns the `ai/ats-analysis` and `ats/document` contracts; their request rules move into FormRequests **unchanged**), session/security config (S4), blog and `POST admin/users` (S5), docs/scheduler (S6).

## Current state (read, not changed)

- 0 FormRequests, 0 Resources, 0 Policies. 22 controllers call `$r->validate([...])` inline, ~40 sites check ownership with `abort_unless($x->user_id === $r->user()->id, 404)` or `where('user_id', …)`.
- Query params read raw: `AnalyticsController::report` (`days`, silently defaults to 30), `LibraryController` (`kind`, `page` already validated inline), `AdminController::users` (`search`, `$r->search`), `SupportController::index` (`status`, `page`), `CvController::store` (`is_primary` via `boolean()`). Page sizes are hard-coded (12/20/100).
- Models return their full attribute set. `User` exposes `session_version`, `suspended`, `role`, `phone` … on `me` and login. `CvDocument` hides `disk_path`/`extracted_text`; `Integration` hides `secret`; `MailSetting` hides secrets.
- Frontend (unchanged until Phase 2b): admin screens read `suspended` and `extracted_text` on admin user detail; `workspace/page.tsx` reads an optional `extracted_text` on the user's own CV list (never sent today since the model hides it).

## Decisions I need from you (before /build)

1. **Shared vs v1-only strictness.** Recommended: legacy and v1 share FormRequests and Resources (one code path; S7 deletes only routes). Consequence: legacy routes also return `422` for invalid enums/pagination and no longer return `session_version` on `me`/login. The current frontend does not read `session_version`. Alternative: keep legacy lenient — doubles the controller code, I advise against it.
2. **Admin reading user CV text** (open since S0): KEEP or REMOVE? REMOVE = admin user detail stops returning `uploads.*.extracted_text` and `GET admin/cv-documents/{cv}/file` is removed from v1 (legacy `uploads/{cv}` too), which changes the admin panel's CV viewer. KEEP = it stays in `AdminUserResource` and is documented. Not guessing; default if you say nothing: **KEEP**, recorded as a privacy risk in the PR.
3. **Provider connection failures** (§18.12): `IntegrationController::test` and `MailSettingsController` check/test move from `422` to `502 upstream_invalid_response` (provider answered with an error) / `503 upstream_unavailable` (unreachable, timeout). The admin UI shows these errors today from `message` on a 422; it will see 502/503 instead. OK?
4. **Pagination.** Spec: `page` ≥ 1, `per_page` 1–50 default 20. Library keeps 12 and applications 20 as defaults (so shapes don't change); `per_page` is added as an optional param. OK?

## Architecture

- `app/Http/Requests/<Area>/…Request.php`; shared base `ApiFormRequest` (authorize `true`, `prepareForValidation` helpers for trim/lowercase/bool cast, failed validation already goes through the S1 envelope).
- Constants single-sourced in `app/Support/Limits.php` (`CV_TEXT_MAX = 30000`, `JOB_DESCRIPTION_MIN/MAX`, …) and model constants (`Application::STATUSES`, …). Rule strings stay byte-identical where today's rules are right, so legacy behavior only changes where §11.4 says so.
- Policies (`app/Policies`, auto-discovered): `CvDocument, CvVersion, JobWorkspace, InterviewSession, CareerReport, Application, JobSearch`; one `view/update/delete` ability = owner. Controllers use `Gate::authorize`; a non-owner gets `404` (policy `denyAsNotFound`), same as today.
- Resources (`app/Http/Resources`): `UserResource` (SPEC §5.8 field list, no `session_version`, no `suspended` for self), `AdminUserResource` (adds `suspended`, `role`, `created_at`), `ApplicationResource`, `CvDocumentResource`, `CvVersionResource`, `JobWorkspaceResource`, `InterviewSessionResource`, `CareerReportResource`, `JobSearchResource`, `IntegrationResource`, `MailSettingResource`, `SupportMessageResource`, `CvTemplateResource`. Resources use `JsonResource::withoutWrapping()` so bodies stay unwrapped (no `data` key): same JSON shape minus the removed columns. Paginated responses keep Laravel's default paginator keys.
- Audit guard test: reflection over every `/api/v1` route that has a body or query: its controller method must type-hint a `FormRequest`, not `Illuminate\Http\Request` (SPEC §5.9).

## Task list

Rules for every task: characterization first, flip a test only in the commit that changes behavior, `composer lint` + `composer test` + `composer test:scripts` before each commit.

### Phase A: foundation

- **T1** `ApiFormRequest` base, `Limits` constants, `Policy` base + audit-guard test written red (allow-list shrinks as areas convert). Acceptance: guard lists every plain-`Request` route.
- **T2** Policies for the 7 models + IDOR matrix test (user A cannot read/update/delete B's records on legacy **and** v1 routes → `404`). Controllers switch from `abort_unless` to `Gate::authorize`. Acceptance: existing characterization tests unchanged.

### Phase B: FormRequests by area (one commit per area, rules unchanged unless noted)

- **T3** Auth: register, login, otp request/verify, profile update, password. Normalization moves to `prepareForValidation`.
- **T4** Documents and applications: cv upload/extract (+ `is_primary` boolean), application store/update, cv-versions, job-workspaces, interviews (start/reply), reports.
- **T5** AI and ATS: chat, cover-letter, ats-analysis, ats/document, the 7 career generators (shared `documents()` rules become one request base). **Rules identical** (Phase 3 owns the contract).
- **T6** Jobs and misc: job search, saved searches, contact message, analytics event, library (`kind` enum, `page`, `per_page`).
- **T7** Admin: users list (`search` ≤ 120, `page`, `per_page`), suspend, warning, site settings, contact-message status, cv-templates, integrations (store/update/reorder/test), smtp save/check/test/connect, analytics `days` enum (`7|30|90` → `422` otherwise; flips the WART test). Acceptance: guard allow-list is empty.
- **T8** Data-provider tests: invalid inputs per FormRequest (missing, too long, wrong enum, nested) → `422` with field errors.

### Checkpoint A (after T8)

Rules identical to today except the documented query enums; show the guard test (empty allow-list) and the IDOR matrix. Review with maintainer.

### Phase C: Resources

- **T9** `UserResource` (me, login, profile update) — flips WART: `session_version` and internal columns gone. Admin user list/detail use `AdminUserResource`.
- **T10** Resources for the user-owned models and admin lists; secrets proven absent by a test that serializes every Resource with a "poisoned" model (secret-like values everywhere) and scans the JSON.
- **T11** Provider-failure codes per decision 3 (502/503) with the S1 safety-net tests flipped; `ErrorCode` docs updated.
- **T12** Admin CV text per decision 2 (only if REMOVE: drop `extracted_text` from `AdminUserResource`, remove the file route, update `docs/ROUTES.md` and contract fixture).

### Checkpoint B (final)

- [ ] No v1 route with input uses plain `Request`; guard test green
- [ ] IDOR matrix green for every owned resource on v1 and legacy
- [ ] No secret/internal column in any response (poisoned-model test)
- [ ] Legacy table unchanged (`LegacyRoutesTest`); parity tests green
- [ ] `composer lint`, `composer test`, `composer test:scripts` (only `ats-document.php` known failure), `composer audit`, fresh clone
- [ ] Open the S3 PR and **STOP**; maintainer merges before S4 is planned

## Risks

| Risk | Mitigation |
| --- | --- |
| Stricter validation breaks the live frontend | Rules stay identical except §11.4 enums; a smoke list of frontend calls is replayed in the parity tests |
| Resources drop a field the frontend reads | Field lists derived from frontend type usage (grepped in `cv-ai`); `AdminUserResource` keeps `suspended` |
| Phase 3 conflict on ATS requests | ATS FormRequests copy today's rules verbatim; no scoring/prompt files touched |
| Resource wrapping changes JSON shape | `withoutWrapping()` plus parity tests against pre-S3 characterization |

## Out of scope

401/403 split and session hardening (S4), blog/`admin/users` create (S5), docs/scheduler (S6), alias removal (S7), any scoring or prompt change.
