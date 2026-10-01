# Implementation Plan: Phase 2, slice S7 — alias sunset

Spec: `SPEC.md` §9 (S7), §18 items 5 and 7. S0–S6 and the migrations hotfix are merged and deployed; the frontend (Phase 2b, `cv-ai#16`) calls only `/api/v1` and its contract test pins all 107 calls to `tests/fixtures/routes-v1.json`. This plan covers **S7 only**.

Branch: `refactor/api-s7` (from `main`). One PR. Stop after opening it.

Status: built; see the PR description for the verification numbers.

## Overview

Remove everything that exists only because the old frontend called pre-`/api/v1` paths: the 100+ deprecated alias routes, the `deprecated` middleware, their tests, and the two endpoints that were never exposed under v1 (`ai/improve-cv`, `cv/{id}/analyze`). **The v1 surface must not change**: the v1 route table (`tests/fixtures/routes-v1.json`, 95 rows) is byte-identical before and after, which is also what keeps the frontend contract valid.

## What goes (verified in the code)

| Area | Removed |
| --- | --- |
| Routes | `routes/legacy.php` (145 lines), its loader and the `legacy.<method>.<uri>` naming loop in `bootstrap/app.php` |
| Middleware / config | `Deprecated` middleware + its alias, `config/api.php` `legacy_sunset`, `API_LEGACY_SUNSET` (`.env.example`), legacy entries in `AuditAdmin` (`legacyContentRead`), `SecurityHeaders::CACHEABLE_ROUTES`, the CSRF exemptions `api/analytics/events` and `api/contact` |
| Legacy-only controller code | `AuthController::logoutLegacy`, `CacheController::clear` (v1 has `destroy`), the legacy `AdminController::warning` response split (v1 keeps the `201`), `AiController::improveCv` + `ImproveCvRequest`, `CvController::analyze` |
| Tests | parity tests (`V1*ParityTest`), `LegacyRoutesTest`, `DeprecationTest`, `ComparesRoutes`, the legacy half of `RouteDocs`, `tests/fixtures/routes-legacy.json`, `tests/support/dump-routes.php` legacy mode |
| Docs | the "Deprecated legacy aliases" section of `docs/ROUTES.md`, README / SPEC mentions |

## What stays (on purpose)

- **Microsoft SMTP OAuth callback** `/api/admin/smtp/microsoft/callback` (`routes/oauth.php`): registered with Microsoft, permanent, never deprecated.
- **`AtsScorer` and the `ats_reports` table**: `AtsScorer` is still used by `JobsController` (match score). `cv/{id}/analyze` was the only writer of `ats_reports`; the table and scorer are removed with the legacy ATS code in Phase 3 ("legacy removal after"), not here.
- **`cv_documents.disk_path` column**: nullable and unused since S6. **Not dropped here** (see decision 1).
- `tests/legacy/*.php` scripts stay (they are the S0 harness) but are moved to v1 paths.

## Test migration (the real work)

~30 test files reference legacy paths (`/api/login`, `/api/me`, `/api/career/...`). Deleting the aliases without porting them would silently drop coverage of behavior that v1 still has (envelope, throttles, ownership, security headers, request id, secret redaction, authorization matrix). So:

1. **Port first, delete second.** Every non-parity test is moved to the v1 path (map in `docs/ROUTES.md` and the old `routes-legacy.json`). Where the legacy response differed from v1 (`logout` 200 vs 204, `cache/clear` 200 vs `DELETE` 204, `password` POST vs `PUT`, `warning` 200 vs `warnings` 201), the test follows v1.
2. **Parity tests only assert "legacy equals v1".** Before deleting them, each v1-only assertion in them (status codes, headers, `assertNotDeprecated`) is checked to exist in a ported characterization or feature test; anything not covered is copied over.
3. **New guard test** `LegacyPathsGoneTest`: every path in the old `routes-legacy.json` (except the OAuth callback) answers `404` with the `not_found` envelope and a request id, so a future route cannot silently reintroduce an alias.
4. **v1 table unchanged:** `V1RoutesContractTest` keeps comparing against `routes-v1.json`; the diff of that fixture in this PR must be empty.

## Tasks

### T1 — Port the tests to v1 (no production code touched)
- Convert every non-parity test and the `tests/legacy/*.php` helpers to v1 paths; remove legacy-vs-v1 comparisons that are now redundant.
- Add `LegacyPathsGoneTest` (it fails at this point, on purpose: it is the T2 target).
- **Acceptance:** `composer test` green except `LegacyPathsGoneTest`; assertion count not lower than before for the ported files (listed in the PR).
- **Verify:** `composer test`, `composer test:scripts`, `pint --test`.

### T2 — Remove the routes and the deprecation machinery
- Delete `routes/legacy.php`, the loader and naming loop, `Deprecated` + alias, `legacy_sunset`, `API_LEGACY_SUNSET`, legacy entries in `AuditAdmin`, `SecurityHeaders` and the CSRF exception list; fix comments in `routes/api.php` and `AppServiceProvider`.
- **Acceptance:** `LegacyPathsGoneTest` green; `routes-v1.json` unchanged; `php artisan route:cache` works; the OAuth callback still resolves.
- **Verify:** `composer test`, `php artisan route:list --path=api`, `route:cache` + `route:clear`.

### T3 — Delete legacy-only code
- Remove `logoutLegacy`, `CacheController::clear`, the legacy warning wrapper, `improveCv` + `ImproveCvRequest`, `CvController::analyze`, and any import or test that only they used. Delete the parity tests, `ComparesRoutes`, `routes-legacy.json` (kept only as a list inside `LegacyPathsGoneTest`), and the legacy half of `RouteDocs` / `dump-routes.php`.
- **Acceptance:** no `legacy`/`deprecated` references left except the guard test and SPEC history; `composer test`, `pint --test`, `composer audit` green.

### Checkpoint A
- [ ] Review with maintainer: diff size, list of ported vs deleted tests, route table diff (must be empty), the 404 guard test.

### T4 — Docs
- Regenerate `docs/ROUTES.md` (no deprecated section), update README, `.env.example`, `docs/ERRORS.md` if affected, `docs/DEPLOYMENT.md` (drop `API_LEGACY_SUNSET`), `SPEC.md` (S7 built, §9, §11 item 7/8, §18 items 5 and 7) and `tasks/todo.md`.
- **Acceptance:** `ROUTES.md` is generated and passes the staleness test; the deployment-guide test still passes (no env var documented that no longer exists).

### T5 — Verify and open the PR
- Fresh clone: `composer install`, `composer test`, `composer test:scripts`, `pint --test`, `composer audit`, `route:cache`.
- Open the PR (deploy notes below), **STOP**.

## Deploy and rollback

- API-only change. The frontend already uses v1 only, so deploy order does not matter after `cv-ai#16` is live; do not merge S7 before the Phase 2b smoke checklist has passed.
- No migration, no new environment variable. `API_LEGACY_SUNSET` can be removed from Sevalla (ignored if left).
- **After deploy:** a stale browser tab still running the old frontend bundle will get `404 not_found` on every call until it is refreshed; nothing else is affected.
- **Rollback:** revert the PR; the aliases come back unchanged (no data is touched).

## Risks

| Risk | Mitigation |
| --- | --- |
| Hidden caller of a legacy path (old tab, script, bookmark) | Frontend contract test shows zero legacy calls; guard test documents the removal; rollback is a revert |
| Silent loss of v1 coverage when parity tests go | Port-first rule (T1) and a before/after assertion count per file |
| Removing code `JobsController` still needs (`AtsScorer`) | Kept; only the `analyze` endpoint goes |
| v1 table changes by accident | `routes-v1.json` diff must be empty; contract test unchanged |

## Decisions needed at Checkpoint A (recommendations first)

1. **`cv_documents.disk_path` drop:** recommend **not in S7**; a separate tiny follow-up (idempotent migration with a `hasColumn` guard, per the hotfix lesson) after S7 is deployed. Keeping S7 free of migrations keeps its rollback a plain revert.
2. **`AtsScorer` / `ats_reports`:** recommend **leave for Phase 3**, as above.
3. **Old route-history fixture:** recommend keeping the legacy path list only inside `LegacyPathsGoneTest`, and deleting `routes-legacy.json`.

## Out of scope

Phase 3 (ATS), Phase 4 (UI), the domain cutover, the `disk_path` column drop, any v1 change.
