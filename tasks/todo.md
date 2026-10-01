# Task list — Phase 2, slice S0 (test & CI harness)

Branch `refactor/api-s0-harness`. Detail and acceptance criteria: `tasks/plan.md`. Commit small and atomic; run `composer lint`/`composer test` before every commit once T1 is done.

- [x] T0 Docs: `SPEC.md` updated with approved decisions, `tasks/plan.md`, `tasks/todo.md`

## Phase A — Tooling

- [x] T1 PHPUnit + Pint dev deps, `phpunit.xml`, `Tests\TestCase`, `/up` health test, composer scripts (`test`, `lint`, `lint:fix`, `audit`)
  - Verify: `composer install && composer test`
- [x] T2 Keep-files + `.gitignore` for `bootstrap/cache`, `storage/framework/*`, `storage/logs`; README note
  - Verify: fresh clone in `/tmp` → `composer install && composer test`

## Phase B — Harness and characterization

- [x] T3 `RefreshDatabase` on SQLite, `CreatesUsers` helpers, fake mail, harness test (`/api/me` 401 vs signed in)
- [x] T4 Characterize auth: register, otp, login (401/403), me, password, logout, revoked session
- [x] T5 Characterize errors/status: 401, 403, 404 (unknown + non-owner), 422 shape, 405, 419, 429, generic 500
- [x] T6 Characterize resources/admin: applications, career, public endpoints, admin access matrix, no secrets in responses

### Checkpoint A (after T1–T3)

- [x] Fresh clone: install + tests green

### Checkpoint B (after T4–T6)

- [x] Characterization green on unmodified app code (`git diff origin/main -- app routes config` empty)
- [ ] Review with maintainer

## Phase C — Legacy tests, audit, style, CI

- [x] T6b (added, approved) Security fix: Gemini key in x-goog-api-key header; App\Support\Redactor on provider errors (ai_usage.error, last_error, logs, responses); WART tests flipped
- [x] T7 Move `tests/*.php` → `tests/legacy/` (paths only), `run.php` runner with known-failure allow-list, `composer test:scripts`
  - Verify: 6 pass, `ats-document.php` = KNOWN FAILURE, exit 0
- [x] T8 `composer update league/commonmark --with-dependencies`; `composer audit` exits 0
- [x] T9 `pint.json` (Laravel preset)
- [x] T10 Format codebase with Pint (format-only commit); verify `php -l`, route list identical, all tests green
- [x] T11 `.github/workflows/ci.yml` (lint, test, test:scripts, audit) + README Development section
- [x] T12 Push, open S0 PR, **STOP** (do not start S1)

### Checkpoint C (final)

- [ ] CI green on the PR
- [x] App diff = security fix commit + format-only commit (AST-compared)
- [x] Known legacy failure visible in runner output
- [ ] Maintainer merges S0 before S1 is planned
