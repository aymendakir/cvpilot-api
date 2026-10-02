# Implementation Plan: Phase 4 API-A — `api-anonymous` (anonymous ATS check and file reading)

Spec: `cv-ai/SPEC.md` §16 (row API-A), §17 items 10 and 18, §18.5 (ATS check from a landing page, the file is never stored); `SPEC-ats.md` §5 (request, response, errors) and §8.4 (privacy tests). The S4 plan is in git history (`tasks/plan.md` at 4028ec7).

Branch: `feat/api-a-anonymous` (from `main` at 4028ec7). One PR. **Answered: D1–D5 = A.** Build T1–T5, open the PR (after cv-ai #24 is merged: one PR at a time) and stop. Rules: small atomic commits; Pint and the tests before every commit; one PR at a time.

## What it adds

Two routes that work **without an account**. They are for the marketing site's inline check (M2) and for reading a CV file without signing in (U10):

| Route                                 | Same as (signed-in)        | Returns                                         |
| ------------------------------------- | -------------------------- | ----------------------------------------------- |
| `POST /api/v1/public/ats/analyses`    | `POST ats/analyses`        | the same `ats-2.0` report (`SPEC-ats.md` §5.2) |
| `POST /api/v1/public/cv/extract`      | `POST cv-documents/extract` | `{ "text": "…" }`                               |

- **Same input and output** as the signed-in routes, plus a Turnstile token. Nothing new for the report.
- **Stateless and private, as today:**
  - the upload is read in place and deleted, and no database row is written;
  - no cookie is set (no session, no CSRF);
  - the logs never contain CV text, the job text, the file name, the score or the IP address;
  - the throttle key is a hash of the IP address, not the address itself.
- **Abuse protection, three layers:**
  1. **Turnstile** (Cloudflare's invisible bot check): the page gets a one-time token, and the API checks it with Cloudflare before reading the file;
  2. **per-IP limits** in the API (D2);
  3. **a global daily cap** for anonymous checks (D2) and a Cloudflare rate-limiting rule at the edge (the setup steps below).
- **Errors:** the same envelope and the same `errors.file` tokens as `ats/analyses`. The anonymous extract route returns tokens too, not sentences (D4). A failed bot check is `422` with `errors.turnstile: ["failed"]`. If Cloudflare cannot be reached, it is `503 upstream_unavailable`.

## What I checked (cvpilot-api @ 4028ec7)

- `Ats\AnalysisController` is already stateless and logs one content-free line. The public route reuses it with a different FormRequest; the engine is unchanged.
- `CvController::extract` uses `DocumentExtractor::extractAndDiscard`. Its unsupported-type error is a sentence (`abort_if(…, 'Unsupported document…')`); the public route maps it to tokens.
- **CSRF:** `SPEC.md` §7 item 9 says "no new CSRF exemptions". The public routes run **outside the `web` group**, like the public blog routes, so they have no session and no cookie. CSRF only protects actions that rely on a cookie, so it does not apply here. **This changes the letter of §7 item 9**; D5 asks for your OK.
- **Client IP behind Cloudflare:** production trusts only the platform proxy (`TRUSTED_PROXIES=*` on Sevalla). Once `api.<domain>` is proxied by Cloudflare, `$request->ip()` becomes a **Cloudflare edge address**, so a per-IP limit would group thousands of visitors together. The fix is to read `CF-Connecting-IP`. That header can be faked if someone reaches the origin directly, so it is read only when `TRUST_CF_CONNECTING_IP=true`. That setting is safe only when the origin accepts Cloudflare traffic alone (D3).
- **CORS:** `config/cors.php` allows `FRONTEND_URL` (the app). The marketing origin `https://<domain>` must be added for these two routes. API-A adds `MARKETING_URL` to the allowed origins; API-B still handles the shared session cookie and trusted hosts.
- Laravel's `Http` client is already used (job search), so verifying with Cloudflare needs no new package.

## Decisions (answered: all A)

**D1 — Route shape.**

- **A (recommended):** a separate `public/` prefix, as in the table. The signed-in routes stay exactly as they are; the public routes have their own limits and Turnstile, and are easy to block at the edge.
- B: let the existing routes accept visitors without an account. This mixes two security models in one route.

**D2 — Limits (all in `.env`, so you can change them without a deploy).**

| Limit                               | Recommended                    | Why                                                                          |
| ----------------------------------- | ------------------------------ | ---------------------------------------------------------------------------- |
| Anonymous ATS check, per IP         | **5 a minute, 30 a day**       | a person checks a few versions of a CV; a script hits the daily cap quickly |
| Anonymous file reading, per IP      | **10 a minute, 60 a day**      | the builder import reads one file at a time                                  |
| All anonymous ATS checks, per day   | **5 000** (`0` = no cap)       | protects the server's CPU (PDF reading) if Turnstile is bypassed            |

When a limit is reached, the response is `429 too_many_requests` with `Retry-After`; the frontend already shows a countdown.

**D3 — Trust `CF-Connecting-IP`?**

- **A (recommended):** yes, with `TRUST_CF_CONNECTING_IP=true` in production, once `api.<domain>` is proxied by Cloudflare. The origin must then accept Cloudflare traffic only; on Sevalla that means allowing only Cloudflare's IP ranges, or using a Cloudflare Tunnel. I check what Sevalla allows and write the exact steps into `docs/DEPLOYMENT.md`.
- B: no. The per-IP limits then count by Cloudflare edge address, which is weak; Turnstile and the global cap remain.

**D4 — Error tokens on the anonymous file-reading route.**

- **A (recommended):** the same `errors.file` tokens as the ATS route (`unsupported_type`, `password_protected`, `corrupt`, `timeout`, …), so the frontend uses one error table (built in U2). The signed-in extract route is unchanged.
- B: keep the current sentences.

**D5 — No session and no CSRF on the two public routes** (the exception to `SPEC.md` §7 item 9 explained above).

- **A (recommended):** yes. They set and read no cookie; Turnstile and the limits protect them. The §7 item 9 text gets a line for this case.
- B: keep them in the `web` group with CSRF. The marketing page would then have to fetch a CSRF token first, which creates a session cookie for every visitor (a database row each, on Sevalla).

## Design

```text
routes/api.php                         public group: withoutMiddleware('web'), throttle by hashed IP, turnstile
app/Http/Middleware/VerifyTurnstile.php reads `cf-turnstile-response` (form field or header), calls siteverify once,
                                        checks success + hostname; 422 errors.turnstile ["missing"|"failed"]; 503 if unreachable
app/Support/ClientIp.php               CF-Connecting-IP when TRUST_CF_CONNECTING_IP, else $request->ip()
app/Providers/AppServiceProvider.php   RateLimiter 'public-ats', 'public-extract' (minute + day per hashed IP, global day cap)
app/Http/Requests/Ats/StorePublicAtsAnalysisRequest.php   = StoreAtsAnalysisRequest + no auth
app/Http/Requests/Cv/PublicExtractRequest.php             tokens in errors.file (D4 = A)
app/Http/Controllers/Api/V1/Ats/AnalysisController.php    unchanged engine; log line gains `access: public|account`
app/Http/Controllers/Api/V1/PublicExtractController.php   extractAndDiscard + UnreadableDocument → tokens
config/services.php                    turnstile: secret, allowed hostnames, timeout; required outside local/testing
config/cors.php                        + MARKETING_URL
```

- **Turnstile token:** read from the `cf-turnstile-response` form field. That is the widget's own field name, so the no-JavaScript form of §18.5 step 3 works unchanged. A `CF-Turnstile-Response` header is accepted for JavaScript calls.
- **Verification:**
  - one POST to `https://challenges.cloudflare.com/turnstile/v0/siteverify` with the secret, the token and the client IP;
  - a 5-second timeout;
  - `success` must be true and `hostname` must be in `TURNSTILE_HOSTNAMES`.
  - Tokens are single-use and expire after 5 minutes (Cloudflare's rule), so the frontend gets a fresh one for each check.
- **Without a secret:**
  - in `local` and `testing`, verification is skipped, with a warning in the log at boot;
  - in production, the routes refuse with `503`, so a missing secret never leaves them open.
- **Order:** throttle → Turnstile → validation → work. A blocked or rate-limited request never reaches the PDF reader.

## Tasks

### T1 — Routes, requests, client IP and limits

- The public group, both FormRequests, `ClientIp`, the rate limiters (D2), `MARKETING_URL` in CORS.
- **Tests:**
  - both routes work without a session and set no cookie (no `Set-Cookie`);
  - the per-IP minute and day limits, and the global cap, with `Retry-After`;
  - the limit is per `CF-Connecting-IP` only when trusted (a forged header is ignored otherwise);
  - the signed-in routes are unchanged;
  - `tests/fixtures/routes-v1.json` is updated.

### T2 — Turnstile

- The middleware and its config.
- **Tests** (Cloudflare faked with `Http::fake`):
  - a missing token → 422; a failed token → 422; a wrong hostname → 422;
  - Cloudflare unreachable → 503;
  - success → the report;
  - the secret is never logged;
  - production without a secret → 503;
  - the file is not read before the check passes.

### T3 — Errors and privacy

- `errors.file` tokens on the public extract route (D4 = A).
- **Tests:** the `AtsAnalysisPrivacyTest` cases run on the public route too (same body for the same input; no file left behind; no row written; no CV text, job text, file name, score or IP in the logs); the error-token cases on both public routes.

### T4 — Docs

- `docs/ROUTES.md`, `docs/ERRORS.md` (`errors.turnstile`), `SPEC.md` §7 item 9 (D5), `SPEC-ats.md` §5 (the public route) and `docs/DEPLOYMENT.md`:
  - the new environment variables;
  - the Cloudflare steps below;
  - how to let only Cloudflare reach the origin on Sevalla (D3).

### T5 — Checks, PR, STOP

- Pint, the full test suite, the route list and the PR. The PR lists the environment variables you need to set. **STOP.**

## Cloudflare steps (you do these in the dashboard; they also go into `docs/DEPLOYMENT.md`)

**Turnstile widget** (needed before M2 goes live; the API works locally without it):

1. In the Cloudflare dashboard, choose **Turnstile** in the left menu, then **Add widget**.
2. **Widget name:** `CVPilot anonymous check`.
3. **Hostnames:** `cvpilottest.online` and `app.cvpilottest.online`. Add the real domain later, when you buy it.
4. **Widget mode:** **Managed** (Cloudflare shows a checkbox only when it is unsure). **Pre-clearance:** No.
5. **Create.** Copy the **Site key** and the **Secret key**.
6. **API host (Sevalla environment):**
   - `TURNSTILE_SECRET_KEY` = the secret key;
   - `TURNSTILE_HOSTNAMES=cvpilottest.online,app.cvpilottest.online`.
7. **Frontend (Cloudflare Worker variables, used from M2):** `NEXT_PUBLIC_TURNSTILE_SITE_KEY` = the site key. The site key is public; the secret key never goes into the frontend.

**Rate-limiting rule at the edge** (extra protection in front of the API):

1. Choose the `cvpilottest.online` zone, then **Security → WAF → Rate limiting rules → Create rule**.
2. **Name:** `Anonymous ATS`.
3. **If incoming requests match:** Hostname equals `api.cvpilottest.online` **and** URI Path starts with `/api/v1/public/`.
4. **Characteristics:** IP. **Rate:** 20 requests per 1 minute. **Action:** Block for 1 minute.
5. Some plans offer fewer period and duration choices, for example 10 seconds on the Free plan. If so, pick the closest values (for example 5 requests per 10 seconds) and tell me which you chose.

**Testing before the widget exists:** Cloudflare publishes test keys. The secret `1x0000000000000000000000000000000AA` always passes and `2x0000000000000000000000000000000AA` always fails; the tests and local development use them.

## Acceptance

- Both routes work without an account and return exactly what the signed-in routes return for the same input.
- No cookie is set, no row is written, no file is left behind, and no CV text, job text, file name, score or IP appears in the logs.
- Turnstile is checked before any file is read; production without a secret refuses (`503`).
- The minute, day and global limits answer `429` with `Retry-After`.
- The signed-in routes and every existing test are unchanged.

## Risks

| Risk                                                 | Mitigation                                                                                         |
| ---------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| Per-IP limits count Cloudflare edges, not visitors    | `CF-Connecting-IP` behind `TRUST_CF_CONNECTING_IP`, plus origin locked to Cloudflare (D3)          |
| Turnstile bypassed by a solver service               | Per-IP limits, the global daily cap and the edge rule; the cap is in `.env`                        |
| Rate-limit counters in the database cache (Sevalla)  | One small row per hashed IP and window; expired rows were never cleaned, so the hourly retention run now deletes them |
| PDF reading is CPU-heavy                             | The existing poppler timeout and the 15 MB limit apply; the global cap bounds the daily total      |

## Out of scope

Anonymous AI routes (after S6), the shared session cookie and trusted hosts (API-B), the frontend use of these routes (U5, M2, U10), removal of the legacy ATS engine.
