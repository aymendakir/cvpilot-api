# Implementation Plan: Phase 4 API-B — `api-hosts` (one sign-in across the site and the app)

Spec: `cv-ai/SPEC.md` §16 (row API-B) and §18.1 (hosts, session, CORS, edge caching); `docs/DEPLOYMENT.md` §6 (runbook from `SameSite=none` to `lax`). The API-A plan is in git history (`tasks/plan.md` at 7358a0c).

Branch: `feat/api-b-hosts` (from `main` at d1295e5, after #28). One PR. **Answered: D1–D3 = A.** Build T1–T4, open the PR and stop.

## Goal

One domain, three hosts, one sign-in that works everywhere:

| Host                     | Needs from the API                                                                                                                 |
| ------------------------ | ---------------------------------------------------------------------------------------------------------------------------------- |
| `app.cvpilottest.online` | signed-in calls with the session cookie (as today)                                                                                 |
| `cvpilottest.online`     | the anonymous routes (API-A), and one signed-in call: `GET me`, so the header can show "Open the app" instead of "Sign in" (§18.1) |
| `api.cvpilottest.online` | the API itself                                                                                                                     |

## What already works (cvpilot-api @ d1295e5), so API-B is small

- **Session cookie settings:** `SESSION_DOMAIN`, `SESSION_SAME_SITE` and `SESSION_SECURE_COOKIE` already exist. The cookie is `HttpOnly` and encrypted, and production refuses to boot without `Secure`.
- **CORS:**
  - already allows `FRONTEND_URL` (the app) and, since API-A, `MARKETING_URL`;
  - credentials are allowed;
  - the `X-CSRF-TOKEN` header is accepted.
- **Runbook:** `docs/DEPLOYMENT.md` §6 already describes the switch to `.<domain>` with `SameSite=lax`.

## What is missing

1. **Trusted hosts.** The API answers any `Host` header today, and Laravel builds absolute URLs from it (the Microsoft sign-in redirect, the `APP_URL` fallbacks). A forged `Host` is a classic way to poison those links or a cache in front. Laravel's `TrustHosts` middleware is not turned on.
2. **A guard against a half-done switch.** If production has `SameSite=lax` but the app, the site and the API are not all under `SESSION_DOMAIN`, nobody can sign in, and nothing says why. Today's boot check covers only `Secure` and the `SameSite` value.
3. **Tests that pin the three-host setup:**
   - the cookie attributes on `.<domain>`;
   - CORS from the app and from the marketing origin, with credentials;
   - a foreign origin refused;
   - a foreign `Host` refused.
4. **Docs:** the runbook for `cvpilottest.online` with three hosts (the Sevalla custom domain for `api.`, Cloudflare DNS records), and the order of the switch.

## Decisions (answered: all A)

**D1 — Which hosts the API accepts.**

- **A (recommended):** a `TRUSTED_HOSTS` setting, defaulting to the host of `APP_URL` (`api.cvpilottest.online`).
  - Enforced only in production, so local and test setups are unaffected.
  - Any other host gets `400 bad_request`; `/up` answers on any host (the platform's health check may use the container address).
- B: no host check (as today).

**D2 — What happens when production is set up inconsistently.** That means `SameSite=lax` or `strict` while the app's or site's address is not under `SESSION_DOMAIN`, or `SESSION_DOMAIN` does not cover `APP_URL`.

- **A (recommended):** refuse to boot with a clear message, like the existing `Secure` check.
  - A wrong deploy then fails visibly instead of silently signing everyone out. Sevalla keeps the previous version running if the new container does not start.
  - It does not fire with today's production settings (`SameSite=none`, no `SESSION_DOMAIN`).
- B: boot anyway, write an error in the log, and show it on the admin panel's system page.

**D3 — One setting for the marketing site's address.** `config/site.php` already has `SITE_URL` (the public site address in the site settings). API-A added `MARKETING_URL` for CORS, so there are two settings for the same address.

- **A (recommended):** CORS reads `SITE_URL`, and `MARKETING_URL` is removed. API-A is not deployed yet, so nobody has set it, and there is one fewer setting to keep in sync.
- B: keep both.

## Design

```text
bootstrap/app.php            trustHosts(at: TrustedHosts::fromConfig()) in production (D1)
app/Support/TrustedHosts.php TRUSTED_HOSTS list, default = host of APP_URL; patterns are anchored
app/Support/SessionSecurity  + same-site check: with lax/strict, SESSION_DOMAIN must cover APP_URL, FRONTEND_URL and SITE_URL (D2)
config/cors.php              SITE_URL instead of MARKETING_URL (D3)
```

## Tasks

### T1 — Trusted hosts (D1)

- In production, a foreign `Host` gets `400`; `api.<domain>` passes.
- `TRUSTED_HOSTS` with two hosts accepts both.
- Local and testing are not enforced.
- `/up` (Sevalla's health check) still answers.
- Each point above is a test.

### T2 — Consistency guard (D2)

Unit tests:

- `lax` with `.cvpilottest.online` and the three hosts under it → boots;
- `lax` with the app on another domain → refuses, naming the setting;
- `none` (today) → boots;
- non-production → never refuses.

### T3 — Three-host tests and CORS (D3)

Tests:

- **Cookie:** the session cookie has `Domain=.cvpilottest.online`, `Secure`, `HttpOnly` and `SameSite=Lax` when configured so.
- **CORS:**
  - a preflight and a `GET me` from `https://app.cvpilottest.online` get `Access-Control-Allow-Origin` and `Access-Control-Allow-Credentials: true`;
  - the same from `https://cvpilottest.online`;
  - `https://evil.example` gets no CORS headers.
- **API-A:** the anonymous routes still set no cookie.

### T4 — Docs, checks, PR, STOP

- **`docs/DEPLOYMENT.md`:**
  - the settings `TRUSTED_HOSTS` and `SITE_URL` (now used for CORS);
  - a §6 runbook for `cvpilottest.online`, in order:
    1. DNS records in Cloudflare;
    2. the `api.` custom domain in Sevalla;
    3. the settings on both sides;
    4. the switch to `SESSION_DOMAIN=.cvpilottest.online` and `SameSite=lax`;
    5. a real sign-in check on both hosts;
    6. what the guard (D2) refuses.
- `.env.example` and the `SPEC.md` decisions.
- Pint, the full suite, then the PR with the settings list. **STOP.**

## Acceptance

- Production accepts only the trusted hosts and refuses an inconsistent session setup at boot. Today's production settings still boot.
- One sign-in cookie on `.<domain>` reaches the API from both `app.<domain>` and `<domain>`. CORS allows exactly those origins, with credentials.
- Every existing test passes, and the anonymous routes still set no cookie.

## Risks

| Risk                                                     | Mitigation                                                                                                        |
| -------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| The trusted-host check blocks Sevalla's own health check | `/up` is tested on the trusted host; I check which `Host` Sevalla's probe sends and allow it if it differs         |
| The boot guard blocks a deploy at a bad moment           | Sevalla keeps the running version; the message names the setting to fix; the guard only fires with `lax`/`strict` |
| Everyone is signed out once at the switch                | Expected (the cookie domain changes); the runbook says so; do it at a quiet time                                   |

## Out of scope

Host routing on the frontend (M0), the marketing header's sign-in island (M1), the anonymous AI routes (after S6).
