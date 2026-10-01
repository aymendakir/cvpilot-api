# Spec: Phase 2 — API contract refactor

- **Repo:** `cvpilot-api` (Laravel 12 / PHP 8.3 / MySQL 8.4). A frontend follow-up lands in `cv-ai` (see §10).
- **Status:** **APPROVED** (answers recorded in §18). Delivery is **one branch + one PR per slice (S0–S7)**, merged by the maintainer before the next slice starts. Plan: `tasks/plan.md`, task list: `tasks/todo.md`.
- **Inputs:** `AUDIT.md` (§3.1, §3.3, §3.5, §4.3 in `cv-ai/docs/AUDIT.md`), a read of all 87 routes, and the frontend's actual calls.
- **Capability map (Phase 0 of the spec skill):** not needed. The five concerns below are layers of one API contract, not independent modules; they ship as ordered slices in one PR (§9).

## 1. Objective

Make `cvpilot-api` a predictable, safe JSON API whose contract the frontend can type against:

1. **Consistent routes** — one naming scheme, versioned under `/api/v1`, no closures, every route named.
2. **Input validation everywhere** — body, query and path inputs, in `FormRequest` classes; nothing reaches a model unvalidated.
3. **One error format** for every non-2xx response, with stable machine-readable codes.
4. **Correct status codes** per a single table (§4).
5. **Auth and security** — clear 401/403 semantics, hardened sessions/CORS/headers, secrets never leak into logs/DB/UI, enforced data retention.
6. **Match what the frontend needs** — implement the endpoints the frontend already calls but the API lacks (blog, `POST admin/users`); stop exposing what nothing uses.

**Users:** the `cv-ai` frontend (signed-out visitors, members, the admin), and the maintainer operating the deployment.

**Non-goals (other phases):** redesigning the ATS scoring contract and `ai/ats-analysis` / `ats/document` payloads (Phase 3), job-search feature work, UI changes (Phase 4), new features (Phase 5).

**Behavior rule (`CLAUDE.md`):** behavior must not change unless listed here. The complete list of _intentional_ changes is §11. Everything else stays identical.

## 2. Assumptions (correct me now or I proceed with these)

1. Session-cookie + CSRF auth stays (the frontend is built on it). Switching to bearer tokens is out of scope unless you choose it in Open Question 2.
2. The frontend and API are deployed independently and not atomically, so old paths must keep working until the frontend is deployed on the new ones.
3. `/api/v1` is the new canonical prefix; today's `/api/*` paths became **deprecated aliases** served by the same controllers, **removed in S7**.
4. Phase 2 ships as **one PR per slice** on `cvpilot-api` (§9), each merged before the next starts, followed (after S6 is deployed) by **one PR on `cv-ai`** that adopts the contract (Phase 2b). `CLAUDE.md` forbids two open PRs at once, so everything is sequential.
5. PHPUnit and Pint are added as dev dependencies, with a GitHub Actions workflow (approved).
6. Database changes are limited to: a `blog_posts` table (new) and dropping the unused `jobs` / `job_matches` tables is **not** part of this phase.
7. `AtsDocumentReview`, `AtsScorer`, AI prompts' _content_, and job providers are not refactored here (only their errors, validation and status codes).

## 3. Routes

### 3.1 Conventions

- Canonical prefix `/api/v1`. Defined in `routes/api.php` (API only). No closures; each route has a name (`v1.<area>.<action>`); controllers are invokable or resource-style.
- Plural kebab-case nouns for resources; HTTP verb carries the action (`GET` list/show, `POST` create, `PUT` replace, `PATCH` partial update, `DELETE` remove).
- Non-CRUD actions are `POST /{resource}/{id}/{verb}` (e.g. `interviews/{id}/reply`) or, for stateless generators, `POST /ai/{verb}`.
- Three scopes: **public**, **authenticated** (`auth.session` middleware, formerly `member`), **admin** (`admin/*`, authenticated + admin).
- Path IDs constrained (`whereNumber`); model binding scoped to the owner (another user's record → `404`, never `403`).
- Legacy aliases: every old path keeps working via the same controller + `Deprecation: true`, `Sunset: <date>` and `Link: <new-path>; rel="successor-version"` headers. Removal was a separate later PR (**done in S7**: the aliases, the `Deprecated` middleware and `API_LEGACY_SUNSET` no longer exist).
- `routes/web.php` keeps only `GET /up` (health) and the Microsoft OAuth browser callback (verify in §9 S2).

### 3.2 Route map (old → new v1)

Unchanged rows are listed so the table is the full contract. Throttles are carried over unless §7 changes them.

| Scope  | Old                                                                                                           | New (v1)                                                                                                                                       | Notes                                                            |
| ------ | ------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------- |
| public | `GET /api/csrf`                                                                                               | `GET csrf`                                                                                                                                     | returns `{ "token" }`                                            |
| public | `GET site-settings`                                                                                           | `GET site-settings`                                                                                                                            |                                                                  |
| public | `POST contact`                                                                                                | `POST contact-messages`                                                                                                                        | 201                                                              |
| public | `POST analytics/events`                                                                                       | `POST analytics/events`                                                                                                                        | 201                                                              |
| public | `GET cv-templates`                                                                                            | `GET cv-templates`                                                                                                                             |                                                                  |
| public | —                                                                                                             | `GET blog`, `GET blog/{slug}`                                                                                                                  | **new** (§8.1)                                                   |
| public | `POST register`                                                                                               | `POST auth/register`                                                                                                                           | 201                                                              |
| public | `POST login`                                                                                                  | `POST auth/login`                                                                                                                              | 200 + user                                                       |
| public | `POST otp/request`                                                                                            | `POST auth/otp/request`                                                                                                                        |                                                                  |
| public | `POST otp/verify`                                                                                             | `POST auth/otp/verify`                                                                                                                         |                                                                  |
| auth   | `POST logout`                                                                                                 | `POST auth/logout`                                                                                                                             | **204**                                                          |
| auth   | `GET/PATCH/DELETE me`                                                                                         | same                                                                                                                                           |                                                                  |
| auth   | `GET me/export`                                                                                               | same                                                                                                                                           |                                                                  |
| auth   | `POST password`                                                                                               | `PUT me/password`                                                                                                                              |                                                                  |
| auth   | `GET/POST cv`                                                                                                 | `GET/POST cv-documents`                                                                                                                        |                                                                  |
| auth   | `POST cv/extract`                                                                                             | `POST cv-documents/extract`                                                                                                                    | stateless                                                        |
| auth   | `DELETE cv/{cv}`                                                                                              | `DELETE cv-documents/{id}`                                                                                                                     |                                                                  |
| auth   | `POST cv/{cv}/analyze`                                                                                        | _legacy only_                                                                                                                                  | no caller; dropped from v1 (OQ 7)                                |
| auth   | `apiResource applications`                                                                                    | `applications` (+`GET {id}`)                                                                                                                   |                                                                  |
| auth   | `GET career/library`                                                                                          | `GET library`                                                                                                                                  |                                                                  |
| auth   | `GET career/dashboard`, `career/analytics`                                                                    | `GET me/dashboard`, `GET me/analytics`                                                                                                         |                                                                  |
| auth   | `career/cv-versions` (+`{id}`)                                                                                | `cv-versions` CRUD                                                                                                                             | `GET/POST`, `GET/PATCH/DELETE {id}`                              |
| auth   | `career/workspaces` (+`{id}`)                                                                                 | `job-workspaces`                                                                                                                               | `GET/POST`, `GET {id}`                                           |
| auth   | `career/interviews …`                                                                                         | `interviews`                                                                                                                                   | `POST`, `GET/DELETE {id}`, `POST {id}/reply`, `POST {id}/finish` |
| auth   | `DELETE career/reports/{id}`                                                                                  | `DELETE reports/{id}`                                                                                                                          |                                                                  |
| auth   | `career/recruiter-view`, `tailor-cv`, `application-pack`, `skill-gap`, `portfolio`, `follow-up`, `diagnostic` | `POST ai/recruiter-view`, `ai/tailor-cv`, `ai/application-pack`, `ai/skill-gap`, `ai/portfolio-review`, `ai/follow-up`, `ai/career-diagnostic` |                                                                  |
| auth   | `ai/chat`, `ai/cover-letter`                                                                                  | same                                                                                                                                           |                                                                  |
| auth   | `ai/ats-analysis`, `ats/document`                                                                             | **unchanged**                                                                                                                                  | contract owned by Phase 3                                        |
| auth   | `ai/improve-cv`                                                                                               | _legacy only_                                                                                                                                  | no caller (OQ 7)                                                 |
| auth   | `jobs/search`, `jobs/links`, `jobs/saved-searches` (+`{id}`)                                                  | same                                                                                                                                           | no caller today; kept (job search is planned)                    |
| admin  | `admin/users` `GET`, `GET {id}`, `PATCH {id}`                                                                 | same                                                                                                                                           | PATCH body `{suspended}`                                         |
| admin  | —                                                                                                             | `POST admin/users`                                                                                                                             | **new** (§8.2)                                                   |
| admin  | `POST users/{id}/warning`                                                                                     | `POST admin/users/{id}/warnings`                                                                                                               | 201                                                              |
| admin  | `GET uploads/{cv}`                                                                                            | `GET admin/cv-documents/{id}/file`                                                                                                             | download                                                         |
| admin  | `GET logs`                                                                                                    | `GET admin/audit-events`                                                                                                                       |                                                                  |
| admin  | `summary`, `system`, `analytics`, `applications`                                                              | same                                                                                                                                           |                                                                  |
| admin  | `site-settings` GET/PUT                                                                                       | same                                                                                                                                           |                                                                  |
| admin  | `contact-messages` GET, `PATCH {id}`                                                                          | same                                                                                                                                           |                                                                  |
| admin  | `cv-templates` CRUD                                                                                           | same                                                                                                                                           |                                                                  |
| admin  | `smtp` GET/PUT, `smtp/check`, `smtp/test`, `smtp/microsoft/connect`                                           | same                                                                                                                                           |                                                                  |
| admin  | `integrations` CRUD, `integrations/{id}/test`, `POST integrations/reorder`                                    | same                                                                                                                                           |                                                                  |
| admin  | `POST cache/clear` (closure)                                                                                  | `DELETE admin/cache`                                                                                                                           | controller, 204                                                  |
| admin  | —                                                                                                             | `GET/POST/GET {id}/PUT/DELETE admin/blog`                                                                                                      | **new** (§8.1)                                                   |

`GET /` (`public/console.html`) is left as is in this phase (removal is cleanup scope).

## 4. Errors and status codes

### 4.1 One envelope

Every non-2xx response under `/api/*` (v1; the legacy aliases were removed in S7 and answer this envelope as unknown routes), including 404 for unknown routes, 405, 419, 429 and unhandled exceptions, is JSON:

```json
{
  "message": "Human-readable, safe to show to the user.",
  "code": "validation_failed",
  "errors": { "email": ["The email field must be a valid email address."] },
  "request_id": "9f2c…"
}
```

- `message` — always present (the frontend already reads only this, so the change is additive).
- `code` — always present; stable snake_case enum (below).
- `errors` — only for `validation_failed`; `{ field: [messages] }` with dot-paths for nested fields.
- `request_id` — same value as the `X-Request-Id` response header (accepts an inbound `X-Request-Id`, else generates one). It is logged with every error.
- Never include stack traces, SQL, file paths, provider responses, API keys or exception class names.
- `Retry-After` on 429 and 503. API routes always render JSON regardless of the `Accept` header.

### 4.2 Codes and statuses

| Status | `code`                      | When                                                                                                                |
| ------ | --------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| 400    | `bad_request`               | malformed JSON, wrong content type                                                                                  |
| 401    | `unauthenticated`           | no session / session expired / session version revoked                                                              |
| 401    | `invalid_credentials`       | wrong email or password on login                                                                                    |
| 403    | `forbidden`                 | authenticated but not allowed (e.g. non-admin on `admin/*`)                                                         |
| 403    | `email_not_verified`        | verified email required                                                                                             |
| 403    | `account_suspended`         | suspended account                                                                                                   |
| 404    | `not_found`                 | unknown route, unknown id, **or another user's resource**                                                           |
| 405    | `method_not_allowed`        |                                                                                                                     |
| 409    | `conflict`                  | state conflict that validation cannot express (reserved; unused by current routes)                                  |
| 413    | `payload_too_large`         | body/upload above limits                                                                                            |
| 419    | `csrf_mismatch`             | missing/expired CSRF token                                                                                          |
| 422    | `validation_failed`         | any validation failure, including domain rules (wrong OTP, wrong current password, unsupported/unreadable document) |
| 429    | `too_many_requests`         | throttled                                                                                                           |
| 500    | `server_error`              | unexpected exception (generic message)                                                                              |
| 502    | `upstream_invalid_response` | AI/job/mail provider answered but output unusable (e.g. unparseable review JSON)                                    |
| 503    | `upstream_unavailable`      | no enabled AI provider, all providers failed, provider timeout                                                      |

Success codes: `200` read/update/action, `201` create, `204` delete and logout and cache clear (no body). No `200` with an error inside.

Current deviations this fixes: AI gateway failure surfaces as generic `500`; `abort(401)` returns `{"message":""}`; `403` is used both for "not signed in" and "not allowed"; validation and abort errors have different shapes; `logout` returns `200`; creating a warning/template returns inconsistent codes; unknown enum/query values are silently defaulted (e.g. `days`).

## 5. Validation

1. **Every** route that reads input (body, query, route params, files) uses a dedicated `FormRequest` under `app/Http/Requests/<Area>/`. Controllers call `$request->validated()` only — never `$request->all()` or `->input()` into models.
2. **Query params validated too:** `page` (int ≥ 1), `per_page` (int 1–50, default 20), `search` (string ≤ 120), `status` / `kind` / `days` as explicit enums. Invalid values → `422`, not silent defaults.
3. **Route params:** `whereNumber` (or slug regex for blog); bound models scoped to the owner through Policies.
4. **Limits single-sourced:** constants for CV text (30 000), job description (30 000), notes, etc., and enum sets (`Application::STATUSES`, provider lists) referenced by rules and models. Max lengths match DB column sizes.
5. **Normalization** in `prepareForValidation()`: trim strings, lowercase emails, cast booleans.
6. **Unknown fields** are ignored (dropped by `validated()`); nested arrays have `max:` bounds.
7. **Files:** size cap = `DocumentExtractor::MAX_KILOBYTES`; type detected from content (already implemented), kept.
8. **Responses via API Resources** (`app/Http/Resources`): stable shapes; `UserResource` returns only `id, name, email, role, verified_at, country, city, phone, language, target_role, experience_level, preferred_countries, work_modes, preferences` (no `session_version`, no `suspended` for self, no hashes); secrets never serialized; `extracted_text` only in the admin detail resource.
9. **Audit guard:** a test fails if any `/api/v1` route with a body or query uses a plain `Illuminate\Http\Request` (reflection check).

## 6. Authorization

- Middleware `auth.session` (renamed from `member`): `401 unauthenticated` for missing/expired/revoked session; `403 account_suspended`; `403 email_not_verified`. Login checks credentials first, then suspended, then verified (same as today).
- Middleware `admin`: `403 forbidden`.
- Policies for `CvDocument, CvVersion, JobWorkspace, InterviewSession, CareerReport, Application, JobSearch`; non-owner → `404`.
- Test matrix: anonymous / user / unverified / suspended / admin × a representative route per scope.

## 7. Security

| #   | Change                                                                                                                                                                                                                                                                            | Why                                   |
| --- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------- |
| 1   | **Session config hardening.** Production refuses to boot with `SESSION_SECURE_COOKIE=false`; default `same_site` becomes `lax`; `none` only when explicitly set. Deployment guidance for same-site hosting (API on a subdomain of the frontend domain) — OQ 2                     | Safari/ITP blocks third-party cookies |
| 2   | **Redact secrets** from provider errors before they are logged, stored in `ai_usage.error` / `integrations.last_error`, or shown in the admin UI. Gemini key moves from the query string to the `x-goog-api-key` header; Jooble's path key is masked in errors                    | AUDIT S5                              |
| 3   | **Moved to Phase 3** (decision after the S4 plan): one prompt envelope for all AI calls (untrusted CV/job/user text JSON-encoded and labelled as data, with length caps). It changes the text sent to the model for every assistant, cannot be verified without model evals and touches prompt wording, so it is tracked in `SPEC-ats.md` §16.1. Length caps already exist as FormRequest rules (S3) | AUDIT S8 — deferred |
| 4   | **Throttles**: login 5/min per email+IP and 30/min per IP (today 20 / 120); register 5/min per IP (unchanged); admin group gets its own limiter (60/min); every state-changing AI route keeps its limiter; 429 includes `Retry-After`                                             | brute force                           |
| 5   | **Headers**: API `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'`, `Cross-Origin-Resource-Policy: same-site`, existing HSTS/nosniff/no-store kept. CORS `allowed_headers` restricted to `Content-Type, Accept, X-CSRF-TOKEN, X-Requested-With, X-Request-Id` | defense in depth                      |
| 6   | **Retention actually runs**: add a scheduler process to the container (separate `scheduler` service in `compose.yaml` running `php artisan schedule:work`, or equivalent) and a test that `cvpilot:prune-temporary` removes expired uploads                                       | AUDIT S3 — 48 h promise               |
| 7   | **Dependencies**: `composer update` to clear the `league/commonmark` advisories; `composer audit` must be clean in the test run                                                                                                                                                   | AUDIT S7                              |
| 8   | **Audit log**: admin reads of user content (`admin/users/{id}`, `cv-documents/{id}/file`) and every admin write are recorded (mostly exists — make it uniform)                                                                                                                    | accountability                        |
| 9   | **CSRF**: exemptions stay limited to `analytics/events` and `contact-messages` (anonymous, throttled, honeypot); no new exemptions                                                                                                                                                |                                       |
| 10  | Register/OTP account enumeration: see OQ 6                                                                                                                                                                                                                                        |                                       |

Not changed in this phase: password hashing/length (min 12), OTP logic, file-based sessions, SMTP host validation.

## 8. New endpoints the frontend already calls

### 8.1 Blog (frontend: `features/blog/blog.ts`, `features/admin/admin-blog.tsx`, sitemap)

- Table `blog_posts` (migration): `id, title(180), slug(191 unique), excerpt(500), body(text), author_name(120), status enum draft|published, published_at nullable, created_at, updated_at`.
- Shape (matches the existing TS `BlogPost`): `{ id, title, slug, excerpt, body, author_name, status, published_at, updated_at }`.
- `GET blog?page=` → paginated `{ data: BlogPost[], current_page, last_page, … }`, published only, newest first, 12/page. `GET blog/{slug}` → published post or `404` (drafts are `404`). Cacheable (`Cache-Control: public, max-age=60`; it is the one route exempt from `no-store`).
- Admin: `GET admin/blog?page=`, `POST admin/blog` (201), `GET admin/blog/{id}`, `PUT admin/blog/{id}`, `DELETE admin/blog/{id}` (204). Slug `^[a-z0-9]+(?:-[a-z0-9]+)*$`, unique (duplicate slug → `422 validation_failed`); `published_at` set when first published; body is stored as text, never trusted as HTML (frontend already renders as text).

### 8.2 `POST admin/users`

Body: `name, email, password, password_confirmation, role (user|admin), verified (bool), confirm_admin (required true when role=admin)`. Creates the user (hashed password, `verified_at` if `verified`), returns `201` `UserResource`, audited, password never returned; `confirm_admin` missing → `422`.

### 8.3 Dropped

`/api/cv/{id}/download` (user download) is **not** implemented: its only caller was removed in the frontend cleanup.

## 9. Delivery slices (for `/plan`)

Build order; each slice is its own branch and PR (small atomic commits, tests, behavior intact except what §11 lists for that slice). `composer audit` must already be clean in S0 because CI runs it.

- **S0 Harness:** dev deps (PHPUnit, Pint), `phpunit.xml`, `composer test|test:scripts|lint|audit` scripts, SQLite-in-memory feature-test base class, existing `tests/*.php` moved to `tests/legacy/` behind a runner, keep-files for `bootstrap/cache` and `storage/framework/*`, GitHub Actions workflow (`composer test`, `pint --test`, `composer audit`), Pint applied to the codebase, and characterization tests that pin today's behavior before any refactor. See `tasks/plan.md`.
- **S1 Error envelope:** exception renderer, request id middleware, JSON-always, `AiGateway` failure mapping, tests for every code in §4.2.
- **S2 Routing:** `routes/api.php`, `/api/v1` + legacy aliases with deprecation headers, route names, closures → controllers, split `CareerController` into `CvVersionController`, `JobWorkspaceController`, `InterviewController`, `ReportController`, `CareerAiController`, `LibraryController`/`DashboardController`; `route:cache` succeeds; contract test (every route named, no closures, scope middleware as expected). Also decide whether the OAuth callback stays in `web.php`.
  - **Built (S2):** `routes/api.php` (v1), `routes/legacy.php` (aliases, one `deprecated:<successor>` middleware per route), `routes/oauth.php` (permanent callback `/api/admin/smtp/microsoft/callback`, never deprecated), `routes/web.php` only `/`. `CareerController` is split into `CvVersionController`, `JobWorkspaceController`, `ReportController`, `LibraryController`, `InsightsController`, `InterviewController`, `CareerAiController`. `Sunset` is sent only when `API_LEGACY_SUNSET` is set. Route list: `docs/ROUTES.md`.
- **S3 Validation:** FormRequests, Policies, Resources, `UserResource`; query-param enums; validation data-provider tests; IDOR tests.
- **S4 Security:** §7 items 1, 2, 4, 5, 8, 9 (item 3 moved to Phase 3, item 7 already clean); secret-redaction tests. **Required pre-deploy step:** production keeps `SESSION_SAME_SITE=none` and `SESSION_SECURE_COOKIE=true` set explicitly in the Sevalla environment (the domain is not live until S6); the new default `lax` would otherwise break cross-site login.
- **S5 New endpoints:** blog + migration, `admin/users` create.
- **S6 Ops:** scheduler as a separate compose service + retention test, deployment doc `docs/DEPLOYMENT.md` (owner buys a domain; frontend on `app.<domain>`, API on `api.<domain>`: DNS, TLS, `APP_URL`, `FRONTEND_URL`, `SESSION_DOMAIN=.<domain>`, `SESSION_SAME_SITE=lax` (**the switch from the explicit `none` that production runs since S4** — change it only after both hostnames are live and a login test passes on them), `SESSION_SECURE_COOKIE=true`, frontend `NEXT_PUBLIC_BACKEND_URL=https://api.<domain>`, scheduler, migration order, rollback).
  - **Built (S6):** the scheduler actually runs (`routes/console.php` was never loaded, so `cvpilot:prune-temporary` did not exist): compose `scheduler` service, `RUN_SCHEDULER=true` for single-container hosts, entrypoint roles, `admin/system` heartbeat, `TRUSTED_PROXIES`. **Sevalla has no persistent disk**, so production is stateless: `SESSION_DRIVER=database`, `CACHE_STORE=database`, `LOG_CHANNEL=stderr`, and **uploaded CV originals are no longer stored** (only `extracted_text` and metadata, kept 48 hours; `cv_documents.disk_path` is nullable and unused, dropped in a later contract step). See `docs/DEPLOYMENT.md`.
- **S7 Alias sunset (separate PR, after the frontend is deployed on v1):** remove the legacy `/api/*` aliases and the legacy-only endpoints `ai/improve-cv` and `cv/{id}/analyze`.
  - **Built (S7):** `routes/legacy.php`, the `Deprecated` middleware, `config/api.php` (`API_LEGACY_SUNSET`) and the legacy entries in the CSRF exceptions, admin audit and cacheable-route lists are gone, together with `AuthController::logoutLegacy`, `CacheController::clear`, the legacy `warning` response, `AiController::improveCv` (+ `ImproveCvRequest`) and `CvController::analyze`. The v1 route table (`tests/fixtures/routes-v1.json`) is unchanged. `LegacyPathsGoneTest` pins that every old path answers `404 not_found`; the parity tests were replaced by `V1BehaviorTest`, and the rest of the suite and `tests/legacy/*.php` call v1 paths. `AtsScorer` and the `ats_reports` table stay until Phase 3; `cv_documents.disk_path` is dropped in a separate contract step.
- **Phase 2b (separate PR, `cv-ai`, after S6 is merged and deployed):** §10.

## 10. What the frontend needs to change (Phase 2b, for reference — not done in this PR)

- `lib/backend-api.ts`: prefix `/api` → `/api/v1` (single place), surface `code` and `errors` on `BackendApiError`, fill `retryAfter` from the header, `isAuthError` = `401` only (today `403` also counts, which would send a suspended or non-admin user to the login page).
- Update call sites for the renames in §3.2 (`login/register/logout/otp/password`, `cv → cv-documents`, `career/* → cv-versions, job-workspaces, interviews, reports, library, me/*, ai/*`, admin renames) plus the raw fetches in `lib/seo.ts` and `features/blog/blog.ts`.
- **Localization (decided):** the frontend maps the error `code` (and, for `validation_failed`, the field names in `errors`) to localized FR/EN text and **does not display the API `message` directly**. `message` is for logs and developers. Replace the status-based `safeMessage` strings in `lib/backend-api.ts` with a `code → i18n key` table, with a generic per-status fallback for unknown codes. `request_id` is shown in a "support reference" detail on server errors.
- Typed response interfaces per endpoint (Resources in §5 are the source).

## 11. Intentional behavior changes (everything else stays the same)

1. Error bodies gain `code`, `errors` (validation), `request_id`; `message` is kept.
2. Status code corrections in §4 (AI/provider failures 500→502/503, `abort(401)` body, logout/delete/cache-clear 204, suspended/unverified get distinct `code`s).
3. New `/api/v1/*` paths; old paths become deprecated aliases (same behavior plus `Deprecation` headers).
4. Stricter validation: invalid enum/pagination/query values now return `422` instead of being ignored; unknown body fields are dropped.
5. `me` / login no longer return internal columns (`session_version` etc.).
6. Login throttle tightened; admin group throttled; session config validated at boot in production.
7. New endpoints: blog (public + admin), `POST admin/users`.
8. `ai/improve-cv` and `cv/{id}/analyze` are not exposed under v1.
9. Gemini key sent in a header; provider errors redacted.
10. A JSON request body that is not valid JSON returns `400 bad_request` (previously it was read as empty input and usually ended as a `422`). _(S1)_
11. Every response carries `X-Request-Id`; 5xx responses always use a generic message (the cause is logged with the request id); `abort(503)` stays `503` instead of being rewritten to `500`; API routes answer JSON regardless of `Accept`. _(S1)_
12. Query and pagination values are validated: `per_page` (1–50, each endpoint keeps its current default size), admin analytics `days` (7, 30 or 90; was silently 30), admin application `status` (enum). Legacy routes get the same rules. _(S3)_
13. `me`, login and profile update return `UserResource` (no `session_version`, `suspended`, `last_seen_at`, `created_at`); every other model response is an explicit field list. _(S3)_
14. Provider failures: integration test and SMTP check/test/connect answer `502 upstream_invalid_response` (provider answered with an error) or `503 upstream_unavailable` (unreachable, timed out, not configured) with a generic body; local PHP/template/database failures are `500`. The redacted reason is stored in `integrations.last_error` / `mail_settings.last_error` (new nullable column), returned in the admin resources and logged with the request id. _(S3)_
15. Admins no longer read user CV text: admin user detail drops `extracted_text`, CV version `content`, interview `cv_text`, report `input` and the temporary `reviews`; `GET admin/cv-documents/{id}/file` (legacy `admin/uploads/{id}`) is removed. _(S3)_
16. Security (S4, legacy routes included): login throttle 5/min per email+IP and 30/min per IP (was 20/120); admin routes 60/min; the session `same_site` default is `lax` and production refuses to boot with an insecure session cookie; the API sends `Content-Security-Policy` and `Cross-Origin-Resource-Policy` headers and a CORS header allow-list (the OAuth callback page is excluded from the CSP). _(S4)_
17. A suspended or unverified account is `403` (`account_suspended` / `email_not_verified`) instead of `401`; unknown or revoked sessions stay `401`. _(S4)_
18. `register` always answers `201` with the same body; an existing email gets a notice mail (one per address every 10 minutes) and no account is created. _(S4)_
19. Every admin write and every read of user content is audited as `admin.<route name>` (the legacy aliases, recorded as `admin.legacy.*` until S7, no longer exist); admin copies of CV, workspace, interview and report records are no longer written and the existing ones are purged. All log lines are scrubbed of provider keys. _(S4)_
20. New endpoints (S5): public `GET blog` and `GET blog/{slug}` (published posts only, cacheable for 60 s, no cookies), admin `admin/blog` CRUD, and `POST admin/users` (`201`, `AdminUserResource`, no email sent; `role=admin` needs `confirm_admin: true`). The deployed frontend calls the legacy paths, so they exist as deprecated aliases until S7. _(S5)_
21. Ops (S6): uploaded CV originals are never stored (privacy: only extracted text and metadata, 48 hours); production sessions, cache and logs no longer use the disk; the retention job is registered and scheduled; `admin/system` gains `retention`; `TRUSTED_PROXIES` makes HSTS work behind a TLS proxy. _(S6)_

## 12. Commands

```bash
# setup
composer install
cp .env.example .env && php artisan key:generate

# dev
php artisan serve --host=localhost --port=8000

# tests (after S0)
composer test                      # php artisan test (PHPUnit, SQLite in memory)
composer test:scripts              # legacy scripts: for t in tests/*.php; do php "$t"; done
vendor/bin/pint --test             # style check (composer lint); `vendor/bin/pint` to fix
composer audit                     # must be clean
php artisan route:list --path=api/v1
php artisan route:cache && php artisan route:clear   # must succeed (no closures)

# container
docker compose up -d --build
docker compose exec api php artisan migrate --force
```

Frontend (Phase 2b): `pnpm typecheck && pnpm lint && pnpm format:check && pnpm test && pnpm build`.

## 13. Project structure (this phase)

```text
routes/
  api.php                  # /api/v1 + legacy aliases
  web.php                  # /up, OAuth callback only
app/
  Http/
    Controllers/Api/V1/…   # one controller per resource; Admin/ subfolder
    Requests/<Area>/…      # FormRequests
    Resources/…            # UserResource, ApplicationResource, BlogPostResource, …
    Middleware/            # AuthenticateSession (auth.session), Admin, RequestId, SecurityHeaders, DeprecatedRoute
  Policies/
  Exceptions/ApiExceptionRenderer.php
  Support/Redactor.php     # secret scrubbing for provider errors/logs
  Services/…               # unchanged layout (restructure is a later phase)
database/migrations/       # + create_blog_posts_table
tests/
  Feature/ Unit/ Contract/ # PHPUnit
  legacy/                  # existing script tests, unchanged
docs/DEPLOYMENT.md
```

## 14. Code style

PSR-12 via Pint (Laravel preset), one statement per line (today's one-line controllers are rewritten as they are touched), typed signatures, no logic in routes.

```php
final class StoreApplicationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'company' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url:https', 'max:2000'],
            'status' => ['nullable', Rule::in(Application::STATUSES)],
            'match_score' => ['nullable', 'integer', 'between:0,100'],
        ];
    }
}

final class ApplicationController
{
    public function store(StoreApplicationRequest $request): JsonResponse
    {
        $application = $request->user()->applications()->create($request->validated());

        return ApplicationResource::make($application)->response()->setStatusCode(201);
    }
}
```

## 15. Testing strategy

- **Framework:** PHPUnit via `php artisan test`; SQLite in memory for most feature tests; MySQL-only raw queries (analytics report) covered by a small suite that runs on MySQL in CI.
- **Levels:**
  - _Contract:_ iterate `Route::getRoutes()` — every v1 route is named, has no closure, has the right scope middleware, every input-bearing route uses a `FormRequest`; legacy alias → same status/body as v1 + `Deprecation` header.
  - _Error envelope:_ one test per code in §4.2, asserting shape and absence of leaked text.
  - _Authorization matrix:_ anonymous / user / unverified / suspended / admin.
  - _IDOR:_ every owner-scoped resource returns 404 for another user.
  - _Validation:_ data-provider of invalid inputs per FormRequest (missing, too long, wrong enum, nested).
  - _Security:_ secret redaction, headers, CORS, throttle + `Retry-After`, session-config boot guard, retention command.
  - _Regression:_ the 6 passing legacy scripts keep passing unchanged.
- **Known failing test:** `tests/ats-document.php` fails on `main` (AUDIT A1, scoring caps). It is **not** touched here (Phase 3 owns it); CI runs it as non-blocking and reports it.
- **Coverage:** every route has ≥ 1 feature test (enforced by the contract test); ≥ 80 % line coverage on `app/Http` and on files changed in this phase.
- **Frontend (Phase 2b):** existing Node tests plus a typed-client test for error parsing; manual check of login/ATS/builder/admin flows against the deployed API.

## 16. Boundaries

**Always**

- Write or update a test first for each behavior in §11; run `composer test`, `pint --test` and `composer audit` before every commit.
- Keep commits small and atomic; Prettier/lint for any frontend file touched.
- Validate every input; use Resources for output; return errors through the envelope only.
- Keep legacy aliases working until the frontend PR is deployed (done: removed in S7).
- Keep old behavior identical except for §11.

**Ask first**

- Adding dependencies beyond PHPUnit and Pint; changing the database (only the `blog_posts` migration is pre-approved by this spec once approved); changing CI/Docker/compose beyond the scheduler service; changing the session/cookie strategy (OQ 2); changing login/registration UX (OQ 6); removing legacy aliases; removing any existing endpoint.

**Never**

- Change ATS scoring or AI prompt wording; edit or delete failing tests to make them pass; commit secrets, `.env`, user data or uploaded CVs; log or return decrypted provider keys, password hashes or OTPs; return stack traces or provider text to clients; force-push or rewrite history; open a second PR while one is open; start `/build` before this spec is approved.

## 17. Success criteria

1. `php artisan route:list --path=api/v1` lists every route in §3.2, each named, none a closure; `php artisan route:cache` succeeds.
2. Every non-2xx response under `/api/*` (v1 and legacy, including unknown routes, 405, 419, 429, 500) validates against the §4.1 envelope in a test.
3. Status codes match §4.2 in tests; no `200` carries an error; `logout`, deletes and cache clear return `204`.
4. No input-bearing v1 route uses plain `Request`; invalid input for each FormRequest returns `422` with field errors.
5. Authorization matrix and IDOR tests pass for every scope/resource.
6. No provider secret appears in `ai_usage.error`, `integrations.last_error`, logs or responses in redaction tests (Gemini header auth verified).
7. `cvpilot:prune-temporary` runs from the scheduler service and its test passes; `composer audit` reports no advisories.
8. Legacy paths return the same data as v1 plus `Deprecation` headers; the current frontend (unchanged) still works against the deployed API (manual smoke: login, ATS check, cover letter, tracker, admin dashboard, blog list/detail).
9. `GET blog`, `GET blog/{slug}`, `admin/blog` CRUD and `POST admin/users` satisfy the frontend types in §8 and have tests.
10. `composer test` and `pint --test` pass; the 6 previously passing legacy scripts still pass; the known failing ATS test is reported, not hidden.

## 18. Decisions (approved)

1. **Route renames:** A — full v1 map (§3.2) with legacy aliases.
2. **Auth:** A — keep cookies. The owner buys a domain: frontend on `app.<domain>`, API on `api.<domain>`; setup steps go in `docs/DEPLOYMENT.md` (S6).
3. **Blog + `POST admin/users`:** implement now (S5).
4. **Tooling:** add PHPUnit and Pint and a GitHub Actions workflow running `composer test`, `pint --test`, `composer audit` (S0).
5. **Legacy aliases:** removed in a follow-up PR right after the frontend is deployed (S7).
6. **Registration enumeration:** A — always `201`; if the email already exists, send an "you already have an account" email (S4).
7. **`ai/improve-cv`, `cv/{id}/analyze`:** legacy paths only, deleted at sunset (S7).
8. **Scheduler:** separate compose service (S6).
9. **`contact` → `contact-messages`:** OK.
10. **Blog extras:** none for now (no image, tags or categories).
11. **Delivery:** one branch and PR per slice S0–S7; the maintainer merges each before the next starts.
12. **Provider connection failures (S3 decision, recorded in S1):** `IntegrationController::test` and `MailSettingsController` keep `422` in S1 (the S1 safety net tags them `validation_failed`). In S3 they use only existing codes: provider answered with an error → `502 upstream_invalid_response`; provider unreachable or timed out → `503 upstream_unavailable`. No new codes.
13. **Phase 2b localization:** see §10 (map `code` to FR/EN text; never show `message`).
