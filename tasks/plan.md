# Implementation Plan: Phase 2, slice S2 — routing and `/api/v1`

Spec: `SPEC.md` §3 (routes) and §9 (S2). This plan covers **S2 only**; S3–S7 are planned one at a time after the previous slice is merged. (S0 and S1 are merged; their plans are in git history.)

Branch: `refactor/api-s2-routing` (from `main` after S1). One PR. Stop after opening it.

## Overview

All API routes move from the closure-heavy `routes/web.php` into `routes/api.php` (versioned `/api/v1`, every route named, no closures, ids constrained) while **every current path keeps working** as a deprecated alias served by the same controller code. `CareerController` (26 methods) is split into focused controllers. `php artisan route:cache` must succeed.

S2 changes **routing only**: no validation, authorization or response-shape changes, except the three documented status/shape differences between v1 and legacy (below). FormRequests/Resources/Policies are S3; the 401→403 split and security items are S4; blog and `admin/users` create are S5.

## Current state (read, not changed)

- `routes/web.php` (formatted in S0) defines `/`, `/api/csrf` and the whole `api` group with **closures** (`/`, `/api/csrf`, `GET me`, `POST admin/cache/clear`). 91 routes in total. Closures make `route:cache` fail.
- The API relies on the `web` middleware group (session cookie, CSRF). Laravel's default `api` group has no session, so `routes/api.php` must be loaded **with the `web` group**.
- Rate limits use `throttle:N,M,<prefix>:` buckets (e.g. `throttle:10,1,cv:`). v1 and legacy routes must share the same bucket prefixes or the effective limit would double.
- `MicrosoftSmtpOAuth::redirectUri()` hard-codes `/api/admin/smtp/microsoft/callback`; that exact URL is registered with Microsoft, so it **cannot move or be deprecated**.
- Frontend (legacy, unchanged until Phase 2b) reads `message` from `POST admin/cache/clear` and calls `POST logout`; both must keep their old responses on the legacy paths.
- `CareerController` has two private helpers (`documents()`, `report()`) shared by the AI generators and `startInterview`.

## Architecture decisions

- **Two route files, one set of controllers.** `routes/api.php` = canonical `/api/v1` (names `v1.<area>.<action>`). `routes/legacy.php` = today's paths, names `legacy.<…>`, middleware `deprecated`. Both are registered through `withRouting(then: …)` inside `Route::middleware('web')->prefix('api')`; `routes/web.php` keeps only `/` (console page, via a controller) and the health route.
- **`Deprecated` middleware** adds `Deprecation: true`, `Sunset: <date>` (config `api.legacy_sunset`, env `API_LEGACY_SUNSET`) and `Link: </api/v1/…>; rel="successor-version"` (computed from a legacy→v1 map). Legacy-only routes (`ai/improve-cv`, `cv/{cv}/analyze`) get the first two headers and no `Link`.
- **OAuth callback is excluded from deprecation** and stays at its current URL, in `routes/legacy.php`'s "permanent" section (no `Deprecation` header, never removed in S7).
- **Shared throttle buckets:** each v1 route reuses its legacy bucket prefix.
- **Scope middleware:** new alias `auth.session` → `Member` (the class is renamed in S4); `member` stays as an alias for legacy.
- **Constrained ids:** `whereNumber` on every `{id}`; slug routes (blog, S5) use a regex.
- **Pure move of controllers** into `App\Http\Controllers\Api\V1\…` (and `…\Admin\…`) as a mechanical commit, verified like the Pint pass (AST comparison). New split controllers go straight there.
- **Differences between v1 and legacy (legacy keeps today's behavior):**

| Route       | Legacy                                                       | v1                                                   |
| ----------- | ------------------------------------------------------------ | ---------------------------------------------------- |
| logout      | `POST logout` → 200 `{message}`                              | `POST auth/logout` → **204**                         |
| cache clear | `POST admin/cache/clear` → 200 `{message}`                   | `DELETE admin/cache` → **204**                       |
| warning     | `POST admin/users/{id}/warning` → 200 `{message,email_sent}` | `POST admin/users/{id}/warnings` → **201** same body |

Implemented as three small legacy controller methods that call the same logic; they are deleted in S7.

- **Tests:** a **route baseline** of the current 91 routes (method, uri, middleware, throttle bucket) is committed as a fixture first, so "legacy unchanged" is enforced throughout the slice; a **parity helper** sends the same request to the v1 and legacy URI and asserts identical status and body.

## Dependency graph

```
T1 baseline fixture + test ──► T2 infrastructure (files, middleware, closures→controllers, controller move)
                                      │
                                      ├──► T3 v1: public + auth + me           ──┐ Checkpoint A
                                      ├──► T4 v1: cv-documents, applications, jobs, ai, ats
                                      ├──► T5 split CareerController: CRUD controllers + v1 routes
                                      ├──► T6 split CareerController: AI generators + interviews
                                      └──► T7 v1: admin
T3–T7 ──► T8 contract test, route:cache in CI, docs ──► T9 PR
```

## Tasks (checklist in `tasks/todo.md`)

**T1. Pin the current route table** (S)

- `tests/fixtures/routes-legacy.json` generated from `route:list --json` (method, uri, action class@method, middleware incl. throttle prefix); `LegacyRoutesTest` asserts every entry still exists with the same method/uri/middleware/controller action (names and deprecation middleware are allowed additions).
- Acceptance: test green on `main`; deleting or changing a route makes it fail.
- Files: fixture, test, a tiny generator script (`tests/support/dump-routes.php`).

**T2. Infrastructure with zero behavior change** (M)

- `routes/legacy.php` (today's routes verbatim, names `legacy.*`, `deprecated` middleware, callback in the permanent section); `routes/api.php` (empty v1 skeleton); `bootstrap/app.php` registers both under `web` + `api` prefix; `routes/web.php` reduced to `/` and health; closures replaced by `ConsoleController`, `CsrfTokenController`, `CurrentUserController`, `CacheController`; `Deprecated` middleware and `auth.session` alias; config `api.legacy_sunset`; mechanical controller move to `Api\V1\…` namespaces (own commit, AST-compared).
- Acceptance: `LegacyRoutesTest` green; all existing tests green; legacy responses carry the deprecation headers except the OAuth callback and `/api/csrf`; `route:cache` succeeds (legacy file has no closures).
- Files: ≤ 5 production files per commit (moves are mechanical).

**T3. v1 public, auth and account routes** (M)

- v1: `csrf`, `site-settings`, `contact-messages`, `analytics/events`, `cv-templates`, `auth/register|login|logout|otp/request|otp/verify`, `me` (GET/PATCH/DELETE), `me/export`, `me/password` (PUT).
- Acceptance: parity tests pass for each pair (same status/body, same throttle bucket); `auth/logout` is 204 and legacy `logout` still 200 `{message}`; deprecation headers only on legacy; names `v1.…`.
- Files: `routes/api.php`, `routes/legacy.php` (link map), `AuthController` (logout split), 2 tests.

### Checkpoint A (after T2–T3)

- [ ] Legacy table identical to the baseline; deprecation headers visible; first v1 slice live with parity tests
- [ ] Review with maintainer (headers, Sunset date, shim approach)

**T4. v1 documents, applications, jobs, AI, ATS** (M)

- v1: `cv-documents` (index, store, `extract`, destroy), `applications` (+ `GET {id}`), `jobs/search|links|saved-searches`, `ai/chat|cover-letter|ats-analysis`, `ats/document` (unchanged path; owned by Phase 3). Legacy-only: `ai/improve-cv`, `cv/{cv}/analyze`.
- Acceptance: parity tests per pair; `GET /api/v1/applications/{id}` returns the user's own record (404 for others); `improve-cv` and `analyze` exist only on legacy with deprecation headers and no `Link`.

**T5. Split `CareerController` (1/2): CRUD and reads** (M)

- New: `CvVersionController`, `JobWorkspaceController`, `ReportController`, `LibraryController`, `InsightsController` (dashboard + analytics). v1: `cv-versions` CRUD, `job-workspaces` (index, store, show), `reports/{id}` DELETE, `library`, `me/dashboard`, `me/analytics`. Legacy `career/*` routes now point at the new controllers.
- Acceptance: `CareerTest` and all parity tests green without editing assertions (behavior identical).

**T6. Split `CareerController` (2/2): AI generators and interviews** (M)

- New: `CareerAiController` (recruiter view, tailor, application pack, skill gap, portfolio, follow-up, diagnostic; shared `documents()`/`report()` in a trait), `InterviewController` (start, show, reply, finish, destroy). v1: `ai/recruiter-view|tailor-cv|application-pack|skill-gap|portfolio-review|follow-up|career-diagnostic`, `interviews…`. `CareerController` is deleted.
- Acceptance: all existing tests green; AI generators keep their throttle buckets; no controller over 150 lines in the new set; legacy scripts green.

**T7. v1 admin routes** (M)

- v1: `admin/users` (list, show, PATCH), `admin/users/{id}/warnings` (POST 201), `admin/cv-documents/{id}/file`, `admin/audit-events`, `admin/summary|system|analytics|applications`, `admin/site-settings`, `admin/contact-messages`, `admin/cv-templates` CRUD, `admin/smtp…` (connect/check/test/save/show), `admin/integrations…` (+ reorder, test), `DELETE admin/cache` (204). The callback stays at its fixed URL.
- Acceptance: admin access matrix test now runs against v1 and legacy; warning shim and cache shim keep legacy bodies; integration secrets still never returned.

**T8. Route contract test, CI and docs** (M)

- `V1RoutesContractTest`: every v1 route has a name `v1.*`, no `Closure` action, the right scope middleware (public / `auth.session` / `auth.session`+`admin`), `whereNumber` on id parameters, and the (method, uri) set equals the table in SPEC §3.2 (encoded as a fixture); every legacy route either maps to a v1 route (same action) or is on the legacy-only list or is the OAuth callback.
- CI: add `php artisan route:cache && php artisan route:clear` to the workflow. `docs/ROUTES.md` lists v1 routes and the legacy→v1 map (a test keeps it in sync with the fixture).
- Acceptance: all tests green; the CI step succeeds locally; the fixture matches SPEC §3.2.

**T9. Final sweep and PR** (S)

- Fresh-clone verification (`lint`, `test`, `test:scripts`, `audit`, `route:cache`); list remaining legacy shims for S7; update `SPEC.md` S2 notes; push; open PR **S2**; STOP.

### Checkpoint B (final)

- [ ] CI green; legacy table identical to the S0 baseline plus names/deprecation only
- [ ] Every v1 route matches SPEC §3.2; `route:cache` works
- [ ] Maintainer merges S2 before S3 is planned

## Risks and mitigations

| Risk                                                        | Impact | Mitigation                                                                                                       |
| ----------------------------------------------------------- | ------ | ---------------------------------------------------------------------------------------------------------------- |
| Moving routes/controllers breaks the live frontend          | H      | Route baseline fixture + parity tests; legacy responses unchanged; deprecation headers are additive              |
| Session/CSRF stop working on routes outside the `web` group | H      | Load both files inside `Route::middleware('web')`; test that login + CSRF + a member route work on v1 and legacy |
| The Microsoft OAuth callback URL changes                    | H      | Fixed URL, excluded from deprecation, covered by a test that asserts the path and the `redirectUri()` agree      |
| Throttle limits double (two buckets)                        | M      | Same bucket prefix per pair; test hits v1 and legacy and sees one counter                                        |
| Splitting the dense `CareerController` introduces a typo    | M      | Mechanical extraction, existing CareerTest/parity tests, AST comparison of moved methods                         |
| `Sunset` date promises a removal date                       | L      | Date is configuration, default set once you choose it (see questions)                                            |
| `route:cache` hides a closure added later                   | L      | CI step + contract test                                                                                          |

## Open questions

1. **`Sunset` date:** pick one (e.g. `2027-03-31`) or leave it unset until the frontend migration (Phase 2b) is scheduled? Recommendation: configurable, default unset (omit the header) until you decide.
2. **OAuth callback** stays at `/api/admin/smtp/microsoft/callback` forever (it is registered in Azure). OK? (Moving it would require updating the Azure app registration.)
3. **Three legacy shims** (logout 200, cache clear 200 + message, warning 200) keep today's responses; v1 gets 204/204/201. OK?
4. **Controller namespace move** (`Api\V1\…`) as a pure mechanical commit in S2 (recommended) vs only for new controllers and the rest in S3.
5. **Shared throttle buckets** between v1 and legacy (recommended) vs separate counters.
6. `GET /api/v1/applications/{id}` is new (spec lists it). Fine to add now?
