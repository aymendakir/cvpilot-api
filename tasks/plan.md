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

## Decisions (answered by the maintainer)

1. **Shared strictness: yes.** Legacy and v1 share FormRequests and Resources.
2. **Admin CV text: REMOVE** (T12). Drop `extracted_text` from admin user detail and remove `GET admin/cv-documents/{cv}/file` (and legacy `admin/uploads/{cv}`), with tests; the PR lists what breaks in the admin UI. _The answer also contained a parenthetical "or: KEEP for now"; I read the first, flat "REMOVE" as the decision and will confirm at Checkpoint A before T12._
3. **502/503 for provider failures: yes** (T11); see the UI finding below.
4. **`per_page` 1–50 with today's defaults** (library 12, applications 20, others 20).

## Frontend audit: calls that carry newly validated values (`cv-ai` @ main, every call goes through `backendRequest`)

| Call | Value sent | New rule | Result |
| --- | --- | --- | --- |
| `admin/analytics?days=` (dashboard) | `7`, `30`, `90` (select options) | `in:7,30,90` | no new 422 |
| `career/library?kind=` (workspace) | `cover_letter`, `report`, `interview`; none (→ `cv`) | `in:cv,interview,workspace,report,application,upload,cover_letter` (already validated today) | no new 422 |
| `admin/contact-messages?page=&status=` | `status` is `""`, `new`, `read`, `closed` | `nullable|in:new,read,closed` (already today) | no new 422: `""` becomes null; pinned by a test in T7 |
| `admin/logs?page=&event=` | `event` free text from filter chips, `page` int | `nullable|string|max:100`, `page` int ≥ 1 (already today) | no new 422 |
| `admin/users?page=&search=` | trimmed search text, `page` int | `string|max:120`, `page` int ≥ 1 (already today) | no new 422 (a >120-char search already 422s) |
| `POST cv` (upload) | `file` only | `is_primary` boolean (already today) | the frontend never sends `is_primary` |
| `per_page` | never sent | `integer|between:1,50`, optional | none |

No frontend call would receive a new 422, so no frontend expectation needs to change. The only strictness that is **new** is `days` (silent default → 422) and the optional `per_page`.

**502/503 finding (decision 3):** every admin handler (`testIntegration`, SMTP check/test/connect, SMTP panel test) catches the error and shows `err.message` in a notice, so there is no blank page. But `backendRequest` replaces any status ≥ 500 with a generic "This service is temporarily unavailable. Your information was not submitted…" message, and S1 also forces a generic server message for 5xx. Consequence: the admin loses the specific reason that a 422 carried (e.g. "SMTP authentication failed"). For integrations the redacted reason is still shown through `last_error` in the list. For SMTP check/test no reason is stored anywhere, so those notices become generic. Options for you at Checkpoint A: accept it; or (cheap, no DB change) log the redacted reason with the request id and let Phase 2b show `request_id`.

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
