# Implementation Plan: Phase 2, slice S4 — security and auth

Spec: `SPEC.md` §6 (authorization), §7 (security), §18 items 2 and 6, and the S4 scope note added after S3. S0–S3 are merged. This plan covers **S4 only**; S5 (blog, `POST admin/users`), S6 (docs, scheduler) and S7 (alias sunset) are planned afterwards, one at a time.

Branch: `refactor/api-s4-security` (from `main` after S3). One PR. Stop after opening it.

## Overview

Harden sessions, throttles, headers and the audit trail; split the 401/403 cases; remove the account-enumeration oracle on register; stop copying user CV text into `admin_review_items`. Legacy and v1 share controllers and middleware, so every change applies to both.

S4 does **not** touch: ATS scoring or any AI prompt wording (Phase 3), blog and `admin/users` create (S5), scheduler/DEPLOYMENT docs (S6), alias removal (S7).

## Current state (read, not changed)

- Session: `same_site` defaults to `none`, `secure` defaults to `true`; nothing stops a production boot with `SESSION_SECURE_COOKIE=false`. `.env.example` uses `lax` and `false` for local work.
- Throttles: `login` is 120/min per IP and 20/min per email+IP; `register` 5/min per IP; the admin group has only the shared `api` limiter (300/min).
- Headers (`SecurityHeaders`): nosniff, X-Frame-Options, Referrer-Policy, Permissions-Policy, `no-store` on `api/*`, HSTS when secure. No CSP, no CORP. CORS `allowed_headers` is `*`; `exposed_headers` is only `Retry-After`.
- `Member` middleware answers `401` for every failure (no session, revoked session, suspended, unverified). `Admin` answers `403`. The class is still called `Member`; aliases `member` and `auth.session` both point to it.
- `register` validates `unique:users`, so an existing email gets a `422` that reveals the account.
- Audit: `AuthController::audit` is called by most admin writes, but not by cache clear, SMTP check/test/connect, integration test, or the admin lists that expose user content (`admin/applications`, `admin/contact-messages`).
- `AdminReview::record` copies the full record (CV text included) into encrypted `admin_review_items` rows for the kinds `cv`, `interview`, `workspace`, `report` and `application`. After S3 only `admin/applications` reads them (kind `application`); the other four kinds are write-only.
- Already done in S0/S1 and only needs pinning: provider-error redaction (`Redactor`, Gemini key in `x-goog-api-key`, Jooble path key), CSRF exemptions limited to `analytics/events` and `contact-messages`, `composer audit` clean (§7 item 7 needs no work).

## Decisions I need from you (before /build)

1. **Session `same_site` default `none` → `lax` (§7 item 1) can lock everyone out.** `lax` cookies are not sent on cross-site requests, so if production still has the frontend and API on different registrable domains (for example `*.vercel.app` + `*.sevalla.app`), login breaks the moment S4 is deployed without an explicit setting. Safe plan: (a) before deploying S4, set `SESSION_SAME_SITE=none` and `SESSION_SECURE_COOKIE=true` explicitly in the Sevalla environment (the code still allows `none` when set explicitly); (b) switch to `lax` only when `app.<domain>` and `api.<domain>` are live (decision 18.2). Is the domain live yet?
2. **§7 item 3 (one prompt envelope for AI calls): I recommend deferring it out of S4.** It changes the text sent to the model for every assistant (CV/job text moves from inline interpolation into a labelled JSON block). That is a behaviour change we cannot verify without model evals, and your rules forbid touching prompt wording; the ATS prompts belong to Phase 3. Length caps already exist as validation rules since S3. Defer to Phase 3 (ATS) and revisit the other assistants afterwards?
3. **Uniform admin audit (§7 item 8):** one `admin.audit` middleware on the admin group writes `admin.<route name>` for every non-GET request and for GETs that expose user content (`users/{id}`, `applications`, `contact-messages`, `audit-events`). Existing event names stay (the admin security screen filters on them). OK?

## Architecture

- **Session hardening:** `config/session.php` default `same_site` becomes `lax`. A boot check in `AppServiceProvider` throws in `production` when `session.secure` is false, or when `same_site=none` while `secure` is false. Nothing changes for local/testing.
- **Middleware rename:** `Member` → `AuthSession` (file, class, alias `auth.session`). The `member` alias and its use in `routes/legacy.php` are replaced by `auth.session`; the alias `member` is removed.
- **401/403 split:** `AuthSession` throws `ApiException` with `Unauthenticated` (no session, expired, revoked `session_version`), `AccountSuspended` or `EmailNotVerified` (403). Frontend `isAuthError` already treats 401 and 403 alike, so a suspended member is still sent to the login screen.
- **Throttles:** `login` = 5/min per email+IP and 30/min per IP; new `admin` limiter 60/min per admin, applied to the admin groups in `api.php` and `legacy.php` (same bucket name, shared). `register` stays 5/min per IP.
- **Headers:** `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'` and `Cross-Origin-Resource-Policy: same-site` on `api/*` responses except the Microsoft OAuth callback page (it is HTML with inline styles). CORS `allowed_headers` = `Content-Type, Accept, X-CSRF-TOKEN, X-Requested-With, X-Request-Id`; `exposed_headers` gains `X-Request-Id` (Phase 2b shows it in error messages).
- **Registration enumeration (decision 18.6):** `register` always returns `201` with the same body. If the email already exists, no account is created and an "you already have an account" email is sent after the response (no timing difference), at most one per address per 10 minutes. `unique:users` is removed from `RegisterRequest`.
- **Admin audit:** `admin.audit` middleware as in decision 3; adds events for cache clear, SMTP check/test/connect, integration test and the user-content lists.
- **AdminReview:** `record()` only stores the `application` kind; `cv`, `interview`, `workspace` and `report` stop being copied (their `forget()` calls stay harmless). A data migration deletes existing rows of those four kinds. Tests assert that no CV text is written by any controller and that the purge works.

## Task list

Rules for every task: write the test first, flip a characterization test only in the commit that changes the behaviour, `composer lint` + `composer test` + `composer test:scripts` before each commit.

- **T1** Session hardening: config default, production boot guard, tests (boot with insecure cookie in `production` fails; local/testing unaffected; `none` accepted only with `secure`).
- **T2** Middleware: rename `Member` → `AuthSession`, drop the `member` alias; 401/403 split (unauthenticated, revoked, suspended, unverified) with tests on v1 and legacy; flips the matching characterization tests.
- **T3** Throttles: login 5/30, admin limiter 60/min; tests for the 429 + `Retry-After` envelope and shared buckets between v1 and legacy.
- **T4** Headers and CORS: CSP/CORP on API responses (not the callback page), CORS allow-list, `X-Request-Id` exposed; tests incl. a preflight with an allowed and a disallowed header.

### Checkpoint A (after T4)

Show: boot guard and session tests, the 401/403 matrix (anonymous / revoked / suspended / unverified / user / admin), throttle numbers, a sample of the new headers. Review with maintainer.

- **T5** Register enumeration: always `201`, same body; duplicate email sends the notice mail after the response, with the per-address cooldown; tests (identical response for new and existing, one mail per window, no mail for new addresses, flips the characterization test that pins the `422`).
- **T6** Uniform admin audit middleware; test that every admin write route and every user-content read writes an `audit_events` row (iterates the route table so new routes cannot skip it).
- **T7** `AdminReview` stops storing CV text, plus the purge migration; tests for each controller path and for the purge; legacy script `workspace.php` assertions that mention admin copies are checked, flipped only if they pin the removed kinds.
- **T8** Secret-redaction tests: pin the existing behaviour end to end (Gemini key never in a URL, Jooble path key masked, `ai_usage.error` and `last_error` redacted, logs free of keys); CSRF exemption list pinned to exactly `analytics/events` and `contact-messages`; `composer audit` clean in the run.
- **T9** `docs/ROUTES.md`/`ERRORS.md` updates, SPEC §11 items, fresh-clone checks, open the PR and **STOP**.

### Checkpoint B (final)

- [ ] CI green; legacy table unchanged except behaviour listed in the PR
- [ ] Deploy note in the PR: set `SESSION_SAME_SITE` and `SESSION_SECURE_COOKIE` explicitly before deploying (decision 1); the new migration runs from the entrypoint
- [ ] Maintainer merges S4 before S5 is planned

## Risks

| Risk | Mitigation |
| --- | --- |
| `lax` default breaks cross-site login in production | Decision 1: explicit env values before deploy; the PR states it at the top |
| Stricter login throttle locks out real users | 5/min per email+IP mirrors the spec; 429 carries `Retry-After`; tests pin the numbers |
| Admin limiter starves the admin panel | 60/min is above the panel's burst (about 5 calls per tab); measured from the frontend call list before building |
| CSP breaks the OAuth callback page | The callback route is excluded and a test pins it |
| Duplicate-email mail used to bomb an inbox | Per-address cooldown plus the existing 5/min per IP register limiter |
| Purge migration deletes data | Only temporary admin copies (48 h lifetime) of four kinds; `application` copies and all user data stay |

## Out of scope

Prompt envelope (deferred, decision 2), blog and `POST admin/users` (S5), scheduler and `DEPLOYMENT.md` (S6), alias removal (S7), ATS and prompt changes.
