# Implementation Plan: Phase 2, slice S6 — ops (scheduler, deployment guide)

Spec: `SPEC.md` §7 item 6, §18 items 2 and 8, and the S6 scope note (including the `SESSION_SAME_SITE` switch added after S4). S0–S5 are merged and deployed. This plan covers **S6 only**; S7 (alias sunset) and Phase 2b (frontend on `/api/v1`) come after.

Branch: `refactor/api-s6-ops` (from `main` after S5). One PR. Stop after opening it.

## Overview

Two promises in the product are not true in production today:

1. **Retention (48 h).** Uploaded CVs, admin copies and old support messages are deleted by `cvpilot:prune-temporary`, which is scheduled hourly in `routes/console.php`, but **nothing runs the scheduler**: no cron entry, no `schedule:work` process. Expired uploads stay on disk.
2. **Same-site cookies.** S4 made `lax` the default, and production runs with an explicit `SESSION_SAME_SITE=none` until the API and frontend share a registrable domain. Nobody has written down how to get there.

S6 makes the scheduler run (and proves it), adds one small piece of proxy configuration the TLS setup needs, and writes `docs/DEPLOYMENT.md`, including the runbook for the switch to `lax`.

## Current state (read, not changed)

- `routes/console.php`: `Schedule::command('cvpilot:prune-temporary')->hourly()`. The command calls `TemporaryDataCleanup` (expired `cv_documents` + their files, support messages older than 90 days, expired `admin_review_items`). It is only tested by a legacy script (`tests/legacy/workspace.php`), not by PHPUnit, and never through the scheduler.
- `compose.yaml`: services `api` (Apache, `./storage` on the `documents` volume) and `db`. No scheduler. No healthcheck on `api`.
- `docker-entrypoint.sh` always runs `php artisan optimize:clear` and `php artisan migrate --force` (retrying up to 12 times) before starting whatever the command is. A second container from the same image would run the migrations again, concurrently.
- `bootstrap/app.php` does not configure trusted proxies. Behind a TLS-terminating proxy (Sevalla, Cloudflare, Caddy) `$request->isSecure()` is false unless the proxy headers are trusted, so `SecurityHeaders` never sends HSTS and URL generation can fall back to `http`.
- `GET admin/system` reports PHP/Laravel versions, database and SMTP state, nothing about the scheduler.
- `.env.example` has `SESSION_*` for local use; the README explains local Docker only. `docs/` holds `ERRORS.md` and `ROUTES.md`.
- The Microsoft OAuth redirect URI is built from `APP_URL` (`/api/admin/smtp/microsoft/callback`) and is registered with Microsoft, so changing `APP_URL` to `api.<domain>` **breaks SMTP sign-in until the new URI is registered**.

## Decisions I need from you (before /build)

1. **How is production run on Sevalla?** The scheduler design depends on it. I need: (a) is the API a single container built from this `Dockerfile`, with a persistent disk mounted at `/var/www/html/storage` (uploads and file sessions live there)? (b) does Sevalla offer a second process/worker or a cron job that runs on the **same disk**? A scheduler that cannot see the same `storage` disk would delete nothing. Recommendation, which works in either case: the entrypoint gets an opt-in `RUN_SCHEDULER=true` that starts `schedule:work` beside Apache in the web container (one extra process, no second service, same disk). The compose file still gets the separate `scheduler` service from decision 8. If Sevalla can run a worker on the same disk, the guide says to prefer it and leave `RUN_SCHEDULER` off.
2. **`TRUSTED_PROXIES` (small change outside the scheduler).** Add `trustProxies(at: env TRUSTED_PROXIES)` to `bootstrap/app.php` (unset = none, `*` for managed platforms) so HSTS and `isSecure()` work behind the proxy. Your spec marks "CI/Docker/compose beyond the scheduler service" as ask-first, and this is application config, so I am asking. Without it the HSTS header from S4/earlier never reaches browsers in production.
3. **Scheduler heartbeat in `admin/system`.** The prune command stores `last_run_at`; `admin/system` returns `retention.last_run_at` and `retention.healthy` (ran within the last 3 hours). It is the only way an admin can see that the scheduler is alive. It adds two fields to an admin-only response; the frontend ignores unknown fields. OK?
4. **Domain.** I will write the guide with `<domain>` placeholders (frontend `app.<domain>`, API `api.<domain>`). Tell me the real domain if you want it filled in.

## Architecture

- **Entrypoint modes.** `docker-entrypoint.sh` runs migrations only for the web role (command `apache2-foreground`, or `RUN_MIGRATIONS=true`); other roles (the scheduler) skip them, so two containers never migrate at once. `RUN_SCHEDULER=true` starts `php artisan schedule:work` in the background of the web container (killed with it on stop).
- **Compose.** New `scheduler` service: same image, `.env`, and `documents` volume; `command: php artisan schedule:work`; `restart: unless-stopped`; waits for a healthy `api` (new healthcheck on `/up`). `RUN_MIGRATIONS=false`.
- **Schedule.** `cvpilot:prune-temporary` stays hourly, gains `->withoutOverlapping()` and `->onFailure()` logging; the command records a heartbeat in the cache (shared `storage` volume).
- **Trusted proxies.** `TRUSTED_PROXIES` env (comma list or `*`); tests send `X-Forwarded-Proto: https` from a trusted and an untrusted peer.
- **Docs.** `docs/DEPLOYMENT.md` (sections below) and a README pointer; `.env.example` gains the new variables with comments. A test keeps the guide honest: every environment variable named in its tables exists in `.env.example` or `config/`.

## `docs/DEPLOYMENT.md` outline

1. Topology: `app.<domain>` (frontend), `api.<domain>` (API), why same-site (Safari/ITP), what stays private (MySQL).
2. DNS and TLS for both hostnames; proxy settings (`TRUSTED_PROXIES`, forwarded headers).
3. Environment: a table per service (API and frontend) with every variable, its production value, and what breaks if wrong (`APP_URL`, `FRONTEND_URL`, CORS, `SESSION_DOMAIN=.<domain>`, `SESSION_SAME_SITE`, `SESSION_SECURE_COOKIE`, `NEXT_PUBLIC_BACKEND_URL`, `BACKEND_INTERNAL_URL`).
4. Scheduler: compose service, the single-container option, how to check it (`admin/system`, `php artisan schedule:list`).
5. Migrations and release order (the entrypoint migrates on start; additive migrations first, code after; the `blog_posts`, `last_error` and purge migrations already shipped).
6. **Runbook: the switch from `SESSION_SAME_SITE=none` to `lax`** (preconditions, ordered steps, verification with a real login and the cookie flags, what users see: everyone is signed out once because the cookie domain changes).
7. **Microsoft OAuth**: re-register the redirect URI `https://api.<domain>/api/admin/smtp/microsoft/callback` before changing `APP_URL`.
8. Rollback for each step; known limits (file sessions and file cache mean one API instance and one shared `storage` disk; do not scale horizontally without moving sessions and cache to a shared store).

## Task list

Rules for every task: write the test first, `composer lint` + `composer test` + `composer test:scripts` before each commit.

- **T1** Retention under test: a PHPUnit test that the schedule contains `cvpilot:prune-temporary` hourly without overlap, and that `schedule:run` at the right minute deletes expired uploads **and their files**, expired admin copies and support messages older than 90 days, while fresh ones, saved work and users survive. Heartbeat written by the command.
- **T2** Entrypoint modes and the compose `scheduler` service plus `api` healthcheck; CI gains `sh -n docker-entrypoint.sh` and `docker compose config`; a test that runs the entrypoint's role decision with a stub (no Docker needed).
- **T3** `TRUSTED_PROXIES` and the `admin/system` retention heartbeat (decisions 2 and 3), with tests.

### Checkpoint A (after T3)

Show: the retention test (including the file removal), the entrypoint behaviour per role, the compose file, the HSTS-behind-proxy test. Review with maintainer.

- **T4** `docs/DEPLOYMENT.md`, README pointer, `.env.example`, and the guide-vs-config test.
- **T5** SPEC §11 note, fresh-clone checks, open the PR (the PR starts with the Sevalla steps for the scheduler and `TRUSTED_PROXIES`) and **STOP**.

### Checkpoint B (final)

- [ ] CI green (lint, tests, scripts, audit, `route:cache`, entrypoint syntax, `docker compose config`)
- [ ] Retention proven by the scheduler path, not only by calling the command
- [ ] Maintainer merges S6 before the domain cutover and before S7 and Phase 2b are planned

## Risks

| Risk | Mitigation |
| --- | --- |
| The scheduler cannot see the uploads disk, so nothing is deleted | Decision 1; the heartbeat only proves the job ran, so the guide also says to confirm on the disk that an expired file is gone; the test proves file removal |
| Two containers migrate at once | Migrations only for the web role; the scheduler sets `RUN_MIGRATIONS=false` |
| `RUN_SCHEDULER=true` leaves a zombie or dies silently | The loop is restarted by a tiny `while` supervisor in the entrypoint and logged; the heartbeat in `admin/system` shows if it stops |
| `TRUSTED_PROXIES=*` accepted from the open internet | Documented as correct only when the container is reachable solely through the platform proxy; default is none |
| Switching to `lax` breaks login | The guide makes it a separate, reversible step after the domain works; rollback is one env var |
| Changing `APP_URL` breaks Microsoft SMTP | Guide step 7: register the new redirect URI first |

## Out of scope

Moving sessions/cache to a shared store, horizontal scaling, a CDN, monitoring/alerting beyond the heartbeat, the actual domain purchase and DNS, Phase 2b (frontend), S7 (alias sunset).
