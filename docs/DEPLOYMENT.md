# Deployment guide

How to run the CVPilot API in production and how to move it onto a shared domain.
Written for **Sevalla** (a container host with a managed database and **no persistent disk**), with notes for plain Docker Compose.

> **Read this first: `storage/` is ephemeral on Sevalla.** Everything under `storage/` is lost on every deploy and restart.
> The app therefore keeps nothing there that matters: sessions and cache live in the database, logs go to stderr,
> and uploaded CV files are **never stored** (see [Privacy](#privacy-what-is-stored)).

## 1. Topology

| Hostname | What | Notes |
| --- | --- | --- |
| `app.<domain>` | Frontend (Next.js) | Browsers talk to the API with credentials (cookies). |
| `api.<domain>` | This API (one container) | Behind the platform's TLS proxy. MySQL is private. |

Both under one registrable domain means the session cookie is **same-site**: it works in Safari/ITP and with `SameSite=lax`.
Until the domain is live, the API and frontend are on different sites and production runs the temporary `SameSite=none` setting
(section 6).

## 2. DNS, TLS and the proxy

1. Point `app.<domain>` at the frontend host and `api.<domain>` at the API host; enable HTTPS on both (the platform issues certificates).
2. The API sits behind the platform proxy, so tell it to trust the proxy's forwarded headers, otherwise it sees plain HTTP and never sends
   HSTS: set `TRUSTED_PROXIES=*` **only if the container is reachable solely through the platform proxy** (true on Sevalla). Use a list
   of addresses/CIDR ranges when you know them. Unset means "trust nothing" (the safe default for local work).
3. Keep MySQL private. It must not have a public port.

## 3. Environment

### API

| Variable | Production value | What breaks if it is wrong |
| --- | --- | --- |
| `APP_ENV` | `production` | Enables the production boot checks (secure cookies). |
| `APP_DEBUG` | `false` | `true` would put internals in logs/pages. |
| `APP_KEY` | `base64:…` (generate once, never change) | Changing it makes stored secrets and encrypted sessions unreadable. |
| `APP_URL` | `https://api.<domain>` (no `/api`) | Wrong CORS origin and wrong Microsoft OAuth redirect URI (section 7). |
| `FRONTEND_URL` | `https://app.<domain>` | CORS only allows this origin (plus `FRONTEND_URL_LOCAL`, `APP_URL`). Exact origin, no trailing slash. |
| `FRONTEND_URL_LOCAL` | empty | Optional second allowed origin. |
| `SITE_URL`, `SUPPORT_EMAIL` | public site URL, support address | Used in public site settings. |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | from the managed database | The container retries migrations for about a minute, then exits. |
| `SESSION_DRIVER` | `database` | `file` loses every session at each deploy (everyone signed out). |
| `CACHE_STORE` | `database` | `file` loses OTP codes and rate-limit counters at each deploy. |
| `LOG_CHANNEL` | `stderr` | `single` writes `storage/logs`, which vanishes on deploy. |
| `SESSION_SECURE_COOKIE` | `true` | Production refuses to boot otherwise. |
| `SESSION_SAME_SITE` | `none` until the shared domain is live, then `lax` (section 6) | `lax` across different sites: nobody can sign in. |
| `SESSION_DOMAIN` | empty until the shared domain is live, then `.<domain>` | Wrong value = cookie not sent. |
| `TRUSTED_PROXIES` | `*` on Sevalla | No HSTS and HTTP URLs behind the TLS proxy. |
| `RUN_SCHEDULER` | `true` (single container) | Without a scheduler nothing is ever deleted (section 4). |
| `RUN_MIGRATIONS` | unset | Web containers migrate by default; set `false` on any extra container that shares the image. |
| `API_LEGACY_SUNSET` | unset until a sunset date is fixed | Adds a `Sunset` header to legacy `/api/*` aliases. |
| `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD` | the owner account | Used by `php artisan db:seed --force`. |
| `MAIL_*` | optional fallback | SMTP is normally saved in Dashboard > Settings > Email. |

### Frontend

| Variable | Value |
| --- | --- |
| `NEXT_PUBLIC_BACKEND_URL` | `https://api.<domain>` (same as the API's `APP_URL`, no `/api`) |
| `BACKEND_INTERNAL_URL` | Optional URL used by server-side rendering (blog, sitemap) when it differs from the public one. |

## 4. The scheduler (retention)

CV text and metadata are kept for **48 hours**, admin copies for 48 hours and support messages for 90 days. The hourly command
`cvpilot:prune-temporary` enforces this, but only if something runs the Laravel scheduler.

- **Sevalla (one container, no cron):** set `RUN_SCHEDULER=true`. The entrypoint starts `php artisan schedule:work` next to Apache and restarts it if it exits.
  The command only touches the database, so it does not need a disk.
- **Docker Compose:** the `scheduler` service runs `schedule:work` with the same image and `.env`; it never migrates.
- **Check it:** `GET /api/v1/admin/system` returns `retention.last_run_at` and `retention.healthy` (ran within 3 hours). `php artisan schedule:list` shows the schedule.

## 5. Releases and migrations

The container applies pending migrations on start (web role only). It retries **only** while the database cannot be reached (connection refused, DNS, timeout, "server has gone away"), up to 12 times 5 seconds apart. Any other migration error (table already exists, bad password, SQL or PHP error) is printed once and the container exits, so the platform log shows the real cause instead of a misleading "database is not ready". Rules of thumb:

0. **Migrations must be repeatable.** MySQL DDL is not transactional: a run that dies between two statements leaves a half-built table and no record, and a plain `Schema::create` then fails on every later start ("table already exists"). Create a table only if it is missing, add every column and index only if it is missing (`Schema::hasTable`, `hasColumn`, `hasIndex`). A test runs every recent migration twice and on half-built tables.
1. Schema changes are **additive first**: a release adds columns/tables, a later release removes what is unused. A rolling deploy briefly runs old code against the new schema.
2. Deploy order for this release: deploy the code (the new `sessions`, `cache`, `cache_locks` tables and the upload cleanup migration run at start), **then** switch the environment variables (`SESSION_DRIVER`, `CACHE_STORE`, `LOG_CHANNEL`, `TRUSTED_PROXIES`, `RUN_SCHEDULER`) and restart.
   Switching `SESSION_DRIVER` signs everyone out once; do it at a quiet moment.
3. Rollback: redeploy the previous image. Additive migrations stay; set `SESSION_DRIVER`/`CACHE_STORE`/`LOG_CHANNEL` back if you need the old behaviour.
4. `cv_documents.disk_path` is now unused and nullable. It is dropped in a later release (contract step).

### What a redeploy does and does not lose

| Lost on every deploy | Kept |
| --- | --- |
| Anything under `storage/` (nothing important lives there any more) | Database: accounts, CV text, applications, sessions, cache, settings, audit log |

## 6. Runbook: from `SameSite=none` to `lax`

Production runs `SESSION_SAME_SITE=none` (set explicitly) while the frontend and API are on different registrable domains.
Move to `lax` only when **both** hostnames are live.

**Preconditions:** `app.<domain>` and `api.<domain>` resolve and serve HTTPS; the frontend already calls `https://api.<domain>`;
the Microsoft redirect URI is registered (section 7).

1. Set the API variables: `APP_URL=https://api.<domain>`, `FRONTEND_URL=https://app.<domain>`.
2. Set the frontend variable `NEXT_PUBLIC_BACKEND_URL=https://api.<domain>` and redeploy the frontend.
3. Set `SESSION_DOMAIN=.<domain>` and `SESSION_SAME_SITE=lax` (keep `SESSION_SECURE_COOKIE=true`), restart the API.
4. **Verify with a real login** from `https://app.<domain>`: sign in, reload, open the admin panel, sign out. In the browser's network panel the
   session cookie must show `Secure`, `HttpOnly`, `SameSite=Lax`, domain `.<domain>`.
5. Everyone is signed out once (the cookie name/domain changed). That is expected.
6. **Rollback** (login fails): set `SESSION_SAME_SITE=none`, clear `SESSION_DOMAIN`, restart. The old hostnames keep working while DNS for the new ones is repaired.

`SESSION_DOMAIN=.<domain>` shares the cookie with every subdomain; do not host untrusted sites on other subdomains.

## 7. Microsoft OAuth (SMTP sign-in)

The redirect URI is built from `APP_URL`: `https://api.<domain>/api/admin/smtp/microsoft/callback`. It is registered with Microsoft and never moves in the API.
**Before changing `APP_URL`, add the new URI to the Microsoft app registration**, then change `APP_URL`. Otherwise "Connect Microsoft" fails until it is added.

## 8. Logs and monitoring

Logs go to stderr (`LOG_CHANNEL=stderr`); read them in the platform's log view. Secrets are redacted from every line, and each error line carries the request id that is also in the API error response.

## 9. Known limits

- One API instance. Sessions and cache are in the database, so more instances work in principle, but the scheduler would then run once per instance: set `RUN_SCHEDULER=true` on exactly one of them.
- File-free: do not add features that write to `storage/` and expect it to survive.

## Privacy: what is stored

- **Uploaded CVs:** the original file is **never stored**. The upload is processed from PHP's temporary file, its text is extracted, and the temporary file is deleted immediately (also when the file is rejected). Only the extracted text and metadata (name, type, size, dates) are kept, for 48 hours.
- Admins cannot read user CV text (S3); admin copies of CV-bearing records are not written (S4).
- Provider keys are encrypted at rest and redacted from logs and errors.
- Releases before S6 stored the original under `storage/`; the S6 migration deletes any that remain and clears the path.
