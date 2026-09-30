# Implementation Plan: Phase 2, slice S0 — test & CI harness

Spec: `SPEC.md` (approved). This plan covers **S0 only**. S1–S7 are planned one at a time after the previous slice is merged.

## Overview

S0 adds the safety net for the API refactor and changes **no runtime behavior**: PHPUnit and Pint, a SQLite-in-memory feature-test base class, characterization tests that pin today's responses (status codes, error bodies, auth/ownership rules), the existing PHP test scripts moved behind a runner, a clean `composer audit`, Pint applied to the codebase, and a GitHub Actions workflow running `composer test`, `pint --test` and `composer audit`.

Branch: `refactor/api-s0-harness` (from `origin/main`). One PR. Stop after opening it.

## Current state (read, not changed)

- `composer.json` has no dev dependencies, no `autoload-dev`, no scripts except package discovery.
- `config/database.php` defines only `mysql`; the existing scripts add an in-memory SQLite connection at runtime. `config/session.php` hardcodes the `file` driver; `config/cache.php` has a file store only.
- 7 script tests in `tests/*.php` bootstrap the app themselves. 6 pass; `ats-document.php` fails on `main` (scoring caps; Phase 3 owns it).
- Fresh clone problem: `bootstrap/cache` and `storage/framework/*` are not tracked, and `.gitignore` ignores their contents, so `composer install` fails until the directories exist.
- `composer audit` reports 2 `league/commonmark` advisories (high + medium). Laravel pulls it in transitively.
- No `.github/`. No Pint config. Code is written as dense one-liners, so `pint --test` fails today.
- A user CV PDF is tracked under `storage/app/private/cv/` (see Open Questions; not part of S0).

## Architecture decisions

- **Tests use SQLite in memory** and a `Tests\TestCase` that defines the `sqlite` connection, `array` cache/session drivers and `SESSION_SECURE_COOKIE=false` at boot. Production config files are **not** edited for testing.
- **Characterization tests pin current behavior, including the warts** (e.g. `{"message":""}` on 401, `200` on logout, 500 on AI failure). Later slices change them deliberately and update the test in the same commit, citing SPEC §11.
- **Legacy scripts keep running unchanged** (only their relative `require` paths change) via `tests/legacy/run.php`. The runner has an explicit allow-list of known failures (`ats-document.php`), prints it as `KNOWN FAILURE`, and fails on any other failure. The allow-list is removed in Phase 3.
- **Pint (Laravel preset) is applied to the whole codebase in one format-only commit**, placed last so it can be dropped for a `pint.json` exclude list if verification shows risk. Verification: `php -l` on every file, `route:list --json` identical before/after, PHPUnit + legacy scripts green.
- **CI is SQLite-only** in S0 (no MySQL service). MySQL-specific queries (analytics report) get a MySQL job in the slice that touches them.
- **Security patch in S0:** `composer update league/commonmark --with-dependencies` (lockfile only) so the required `composer audit` CI step is green from day one.

## Dependency graph

```
T1 tooling + health test ──► T2 fresh-clone hygiene
        │
        └──► T3 DB harness ──► T4 auth characterization ─┐
                      │                                   ├──► (Checkpoint B)
                      ├──► T5 error/status characterization ─┤
                      └──► T6 resource/admin characterization ─┘
T1 ──► T7 legacy scripts runner
T1 ──► T8 audit clean
T1 ──► T9 Pint config ──► T10 format codebase (last code change; needs T3–T7 green)
T1,T7,T8,T9 ──► T11 CI workflow (+ README dev section)
T0 (docs, done) … T12 open PR
```

T1 must come first; T4–T6 can be done in any order after T3.

## Tasks (see `tasks/todo.md` for the checklist)

### Phase A — Tooling

**T1. PHPUnit + Pint installed; `composer test` runs a green health test** (S)

- Add `phpunit/phpunit` and `laravel/pint` as dev deps; `autoload-dev` (`Tests\` → `tests/`); `phpunit.xml` (suites `Unit`, `Feature`; env `APP_ENV=testing`, `APP_KEY`, `SESSION_SECURE_COOKIE=false`, `CACHE_STORE=array`); `tests/TestCase.php` (defines sqlite connection, array session/cache); `tests/Feature/HealthTest.php` (`GET /up` → 200).
- Scripts: `test`, `lint` (`pint --test`), `lint:fix`, `audit`.
- Acceptance: `composer test` passes with ≥ 1 test; `composer lint` runs (may report style failures until T10).
- Verify: `composer install && composer test`.
- Files: `composer.json`, `composer.lock`, `phpunit.xml`, `tests/TestCase.php`, `tests/Feature/HealthTest.php`.

**T2. Fresh clone installs and tests without manual steps** (XS)

- Track keep-files for `bootstrap/cache`, `storage/framework/{cache,sessions,views}`, `storage/logs` and adjust `.gitignore` so they survive (`!.gitkeep`); README "Development" notes.
- Acceptance: `git clone` into a temp dir, `composer install`, `composer test` succeed with no `mkdir`.
- Verify: scripted fresh clone in `/tmp`.
- Files: `.gitignore`, 5 keep files, `README.md` (docs only).

### Phase B — Test harness and characterization

**T3. DB-backed feature harness** (S)

- `RefreshDatabase` on SQLite with all migrations; helpers in `tests/Concerns/CreatesUsers.php`: `makeUser()`, `makeAdmin()`, `signIn($user)` (session `user_id` + `session_version`); fake `PlatformMail` via container.
- Acceptance: a test migrates the schema, creates a user, and `GET /api/me` returns it when signed in and 401 otherwise.
- Risk: a migration may not run on SQLite (MySQL-only syntax) → fix in the test setup, never in migrations.
- Files: `tests/TestCase.php`, `tests/Concerns/CreatesUsers.php`, `tests/Feature/HarnessTest.php`.

**T4. Characterize auth flows** (M)

- `register` (201 body), duplicate email (422 today), `otp/request` (generic message; mail faked), `otp/verify` success/invalid/expired/attempt limit, `login` (200 user; 401 bad credentials; 403 suspended; 403 unverified), `me` GET/PATCH, `password`, `logout` (200 today), session-version revocation → 401.
- Acceptance: tests pass against unmodified code; each asserts status + JSON keys actually returned.
- Files: `tests/Feature/Characterization/AuthTest.php`, `.../AccountTest.php`.

**T5. Characterize errors and status codes** (S)

- 401 body, 403 (admin route as user), 404 unknown API route and non-owner resource, 422 validation shape (`message` + `errors`), 405, 419 (CSRF enabled for this test), 429 (throttle), generic 500 body on an unhandled exception.
- Acceptance: each current shape recorded, including `{"message":""}` cases.
- Files: `tests/Feature/Characterization/ErrorShapesTest.php`.

**T6. Characterize resources and admin access** (M)

- Applications CRUD (201/200/204, ownership 404), `career/cv-versions` create/list/delete, `career/library`, public `site-settings`, `contact` (201, honeypot 422), `cv-templates`, `analytics/events`; admin matrix (anonymous/user/admin) for `admin/users`, `admin/summary`, `admin/integrations` (secret never in response).
- Acceptance: pins current status + keys; no secrets in any response.
- Files: `tests/Feature/Characterization/ApplicationsTest.php`, `CareerTest.php`, `PublicEndpointsTest.php`, `AdminAccessTest.php`.
- Note: MySQL-only analytics report is not characterized here (covered in the slice that changes it).

### Checkpoint A (after T1–T3)

- [ ] `composer install && composer test` green on a fresh clone
- [ ] harness creates users/sessions; `git status` clean after tests

### Checkpoint B (after T4–T6)

- [ ] all characterization tests green on unmodified app code (`git diff main -- app routes config` empty)
- [ ] review with you: is the pinned behavior the behavior we intend to change in S1–S4?

### Phase C — Legacy tests, audit, style, CI

**T7. Legacy script tests behind a runner** (S, mechanical)

- `git mv tests/*.php tests/legacy/`; fix `__DIR__.'/../vendor'` → `'/../../vendor'` and similar; add `tests/legacy/run.php` (runs each script in a subprocess, prints PASS/FAIL, allow-list `ats-document.php` as `KNOWN FAILURE`, exit 1 on any other failure); script `composer test:scripts`.
- Acceptance: 6 scripts pass, `ats-document.php` reported as known failure, exit code 0; removing it from the allow-list makes the runner fail (manual check).
- Files: 7 moved scripts (path edits only), `tests/legacy/run.php`, `composer.json`.

**T8. `composer audit` clean** (XS)

- `composer update league/commonmark --with-dependencies` (lock only). If it cannot clear both advisories, stop and report.
- Acceptance: `composer audit` exits 0; `composer test` and `composer test:scripts` still green.
- Files: `composer.lock`.

**T9. Pint configuration** (XS)

- `pint.json` (preset `laravel`; exclude `vendor`, `storage`, `bootstrap/cache`).
- Acceptance: `vendor/bin/pint --test` runs and lists the files it would change.
- Files: `pint.json`.

**T10. Format the codebase with Pint — format-only commit** (M, large diff)

- Run `vendor/bin/pint`; commit as `style: format codebase with Pint`, nothing else in that commit.
- Acceptance: `pint --test` passes; `php -l` OK for every changed file; `php artisan route:list --json` identical to before; `composer test` + `composer test:scripts` green.
- Fallback if any check fails: revert, list offending files in `pint.json` `exclude`, and note it in the PR.
- Files: most of `app/`, `routes/`, `config/`, `database/` (mechanical).

**T11. GitHub Actions CI + developer docs** (S)

- `.github/workflows/ci.yml` on `push` to `main` and `pull_request`: PHP 8.3 (`shivammathur/setup-php`, extensions `mbstring, zip, gd, pdo_sqlite, intl, dom`), Composer cache, `composer install --no-interaction --prefer-dist`, then `composer lint`, `composer test`, `composer test:scripts`, `composer audit`.
- README "Development" section with the commands (S0 scope only).
- Acceptance: workflow YAML parses locally; the PR's own run is green.
- Files: `.github/workflows/ci.yml`, `README.md`.

**T12. Open the S0 PR and stop** (XS)

- Push `refactor/api-s0-harness`; PR title `test: API test harness, Pint and CI (Phase 2, S0)`; body lists commits, verification output, known failing legacy test, what S1 will change (the pinned behaviors). Do **not** start S1.

### Checkpoint C (final)

- [ ] CI green on the PR
- [ ] `git diff origin/main -- app routes config database` is formatting-only (Pint commit) — no logic change
- [ ] Legacy known failure is reported, not hidden
- [ ] You merge; only then I plan S1

## Risks and mitigations

| Risk                                                         | Impact | Mitigation                                                                                               |
| ------------------------------------------------------------ | ------ | -------------------------------------------------------------------------------------------------------- |
| A migration fails on SQLite                                  | M      | Adapt the test harness (never migrations); if impossible, use a MySQL service in CI for feature tests    |
| Pint rewrites dense code and subtly changes behavior         | H      | Format-only commit, last; `php -l`, route list diff, all tests; easy revert to `exclude` fallback        |
| Characterization tests are brittle (pin warts)               | L      | Intentional: each later slice edits them in the same commit as the behavior change, referencing SPEC §11 |
| `composer update league/commonmark` pulls unrelated upgrades | M      | `--with-dependencies` limited to that package; review lock diff                                          |
| CI cannot run locally                                        | L      | Validate YAML locally; first CI run is the PR itself                                                     |
| `RefreshDatabase` slow                                       | L      | Migrations are small; in-memory SQLite                                                                   |

## Open questions

1. A user CV PDF is tracked at `storage/app/private/cv/1/…pdf` (audit finding P0). It is outside S0's scope. OK to handle it in a tiny separate PR after S0 (untrack + ignore `storage/app`; history purge is your decision)?
2. Pint scope: OK to format the whole codebase in S0 (recommended), or use an exclude list and format file by file as later slices touch them?
3. PR title/body language: fine as proposed?
