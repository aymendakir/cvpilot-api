# Implementation Plan: Phase 2, slice S1 — error envelope

Spec: `SPEC.md` §4 (approved). This plan covers **S1 only**; S2–S6 are planned one at a time after the previous slice is merged. (S0's plan is in git history and PR #14; all of its tasks were completed and merged.)

Branch: `refactor/api-s1-error-envelope` (from `main` after S0). One PR. Stop after opening it.

## Overview

Every non-2xx response under `/api/*` becomes one JSON envelope — `{ message, code, errors?, request_id }` — with the status codes of SPEC §4.2, a stable `X-Request-Id`, no leaked internals, and JSON regardless of the `Accept` header. AI/provider failures stop being a generic `500` and become `503 upstream_unavailable` / `502 upstream_invalid_response`.

S1 changes **error responses only**. Success responses, routes, validation rules and authorization behavior are untouched (route renames and `204` on logout are S2; FormRequests are S3; the 401→403 split for suspended/unverified members is S4).

## Current state (read, not changed)

- `bootstrap/app.php` has one `render` callback: for `api/*` it rewrites any status ≥ 500 to `500 {"message":"Service temporarily unavailable."}` (this is why `abort(503)` becomes `500`). Everything else uses Laravel's default JSON rendering **only if** the client sends `Accept: application/json`; without it, API 404s are HTML.
- Error shapes today (pinned by the S0 characterization tests, marked `WART`): `{"message":""}` for bare `abort(401|403|404)`, route path leaked in 404/405 messages, model class leaked in model-not-found 404, `{message, errors}` for validation, `{message}` only for `abort(422, …)`, no `code`, no `request_id`.
- Manually built error responses that bypass the exception handler: `AuthController` (login 401/403 ×2, OTP mail failure 503), `AiController` (502 ×2), `MailSettingsController` (422 ×4), `IntegrationController::test` (422).
- `AiGateway` raises `abort(503, …)` (no provider) and `RuntimeException(…, 503)` (all providers failed); neither is an HTTP exception after the render callback, so both become `500`.
- Middleware: only `SecurityHeaders` is global (`append`).

## Architecture decisions

- **One renderer, one enum.** `App\Exceptions\ErrorCode` (string-backed enum: HTTP status, default safe message, `retryable` flag) and `App\Exceptions\ApiExceptionRenderer` (invokable, registered in `withExceptions`) map any `Throwable` for `api/*` to the envelope. No controller builds error JSON by hand after S1, except through the safety net below.
- **Developer-authored messages pass through for 4xx** (`abort(422, 'Invalid or expired code.')` keeps its text; the frontend already displays these). **5xx and unknown exceptions always use a fixed generic message**; the real message goes to the log with the request id. Framework messages that leak internals (route path, model class, SQL) are replaced by the code's default message.
- **`ApiException`** (extends `HttpException`) carries `code`, optional `errors` and headers, for the few places that need a specific code (login: `invalid_credentials`, `account_suspended`, `email_not_verified`). `UpstreamUnavailableException` (503) and `UpstreamInvalidResponseException` (502) extend it.
- **Safety net for hand-built error responses:** an `EnsureErrorEnvelope` response middleware (global, `api/*` only) adds `code` (derived from status) and `request_id` to any JSON error body that lacks them, keeping the existing keys (`ok`, provider hints). This guarantees the contract even for `MailSettingsController`/`IntegrationController::test` bodies that this slice does not rewrite.
- **Request id:** `RequestId` global middleware (first in the stack): accepts an inbound `X-Request-Id` only if it matches `^[A-Za-z0-9._-]{8,64}$`, else generates a UUIDv4; stores it on the request, adds it to `Log::withContext`, and sets the response header on **every** response (success and error, API and web).
- **JSON-always:** `ForceJsonResponses` sets `Accept: application/json` on `api/*` requests before routing, so even unmatched routes and early middleware failures render JSON.
- **Retry-After:** kept from Laravel's throttle on 429; fixed `Retry-After: 30` on 503 responses produced by `UpstreamUnavailableException`.
- **Non-API routes** (`/`, `/up`, OAuth browser callback) are not touched.
- **Tests flip in the same commit as the behavior** (S0 rule): each `WART` assertion this slice fixes is rewritten; the remaining WARTs stay for S2–S4.

## Message catalog (defaults; developer messages override on 4xx)

| code                        | status | default message                                                                           |
| --------------------------- | ------ | ----------------------------------------------------------------------------------------- |
| `bad_request`               | 400    | The request could not be understood.                                                      |
| `unauthenticated`           | 401    | Authentication is required.                                                               |
| `invalid_credentials`       | 401    | Invalid credentials. _(existing text)_                                                    |
| `forbidden`                 | 403    | You do not have permission to do this.                                                    |
| `email_not_verified`        | 403    | Verify your email first. _(existing)_                                                     |
| `account_suspended`         | 403    | This account has been suspended by an administrator. Please contact support. _(existing)_ |
| `not_found`                 | 404    | The requested resource was not found.                                                     |
| `method_not_allowed`        | 405    | This method is not allowed for this endpoint.                                             |
| `conflict`                  | 409    | The request conflicts with the current state.                                             |
| `payload_too_large`         | 413    | The request is too large.                                                                 |
| `csrf_mismatch`             | 419    | CSRF token mismatch. _(existing)_                                                         |
| `validation_failed`         | 422    | Laravel's validation summary _(existing)_                                                 |
| `too_many_requests`         | 429    | Too many requests. Please wait and try again.                                             |
| `server_error`              | 500    | Service temporarily unavailable. _(existing)_                                             |
| `upstream_invalid_response` | 502    | The service could not complete this request. Please retry.                                |
| `upstream_unavailable`      | 503    | The service is temporarily unavailable. Please try again later.                           |

## Dependency graph

```
T1 request id + JSON-always ──► T2 envelope renderer (framework errors) ──► T3 domain codes (login)
                                        │
                                        ├──► T4 upstream errors (AiGateway, AiController)
                                        ├──► T5 safety net for hand-built responses
                                        └──► T6 logging with request id + docs/ERRORS.md
T3,T4,T5,T6 ──► T7 final sweep + PR
```

## Tasks (checklist in `tasks/todo.md`)

**T1. Every API response carries a request id and API errors are always JSON** (S)

- `RequestId` and `ForceJsonResponses` middleware, registered in `bootstrap/app.php`; `X-Request-Id` on all responses.
- Acceptance: inbound valid id echoed; invalid/over-long/absent → new UUID; header present on 200, 404 and 500; `GET /api/does-not-exist` **without** `Accept` is JSON; `/up` and `/` are not changed except for the header.
- Verify: new `RequestIdTest`; full suite.
- Files: 2 middleware, `bootstrap/app.php`, `tests/Feature/Api/RequestIdTest.php` (+ update one WART in `ErrorShapesTest`).

**T2. Framework errors render as the envelope** (M)

- `ErrorCode`, `ApiException`, `ApiExceptionRenderer`; mapping for `ValidationException` (422 + `errors`), `HttpException` (by status; 4xx developer message kept, empty → default), `NotFoundHttpException`/`ModelNotFoundException` (404, no route/model text), `MethodNotAllowedHttpException` (405, `Allow` header kept), `TokenMismatchException`/419, `ThrottleRequestsException` (429, `Retry-After` kept), `PostTooLargeException` (413), `AuthenticationException` (401), `AuthorizationException`/403, any other `Throwable` (500 generic; logged).
- Acceptance (tests, one per code): 400 (explicit `abort(400)`), 401, 403, 404 (route + model + non-owner), 405, 413, 419, 422 (shape: `message`, `code`, `errors`, `request_id`), 429 (+ `Retry-After`), 500, 409 (via `ApiException`); body never contains route path, model class, exception text, SQL, file paths; `request_id` equals the header.
- Flips WARTs: 401/403 empty message, 404/405 leaks, no code/request_id, domain `422` shape (now has `code`), 500 body.
- Files: `app/Exceptions/ErrorCode.php`, `ApiException.php`, `ApiExceptionRenderer.php`, `bootstrap/app.php`, `tests/Feature/Api/ErrorEnvelopeTest.php` (+ edits to `ErrorShapesTest`, `AuthTest`, `ApplicationsTest`, `CareerTest`, `AdminAccessTest` assertions that pinned `{"message":""}`) — test edits are mechanical; production files ≤ 5.

### Checkpoint A (after T1–T2)

- [ ] All framework-originated errors (every status in §4.2 except 502/503 and login-specific codes) match the envelope; full suite green
- [ ] Review with maintainer: message wording, `Retry-After`, what the frontend will now see

**T3. Login and mail errors carry their specific codes** (S)

- `AuthController::login` throws `ApiException` for `invalid_credentials` (401), `account_suspended` (403), `email_not_verified` (403); OTP mail failure becomes `UpstreamUnavailableException` (503, was a hand-built 503). Texts and statuses unchanged.
- Acceptance: same statuses/messages as the S0 characterization tests plus `code`; audit-log rows unchanged; password check still runs before the suspended/verified checks.
- Files: `AuthController.php`, `AuthTest.php`.

**T4. AI/provider failures are 503/502, not 500** (M)

- `UpstreamUnavailableException` (503, `Retry-After: 30`), `UpstreamInvalidResponseException` (502). `AiGateway`: no enabled provider and all-providers-failed throw `UpstreamUnavailableException` (details only in logs/`ai_usage`, already redacted). `AiController::atsAnalysis`: the two hand-built 502s become `UpstreamInvalidResponseException`.
- Acceptance: `ai/chat` with no provider → `503 upstream_unavailable`; with all providers failing → `503`; unparseable ATS review JSON → `502 upstream_invalid_response`; no provider text, key or provider name in any response; `ai_usage` rows and `last_error` unchanged (and still redacted); legacy script tests still pass.
- Flips WARTs: `abort(503)`→500 rewrite and both AI 500 cases.
- Files: 2 exceptions, `AiGateway.php`, `AiController.php`, `ErrorShapesTest.php`/new `UpstreamErrorsTest.php`.

**T5. Safety net for hand-built error bodies** (S)

- `EnsureErrorEnvelope` global response middleware (`api/*`, JSON, status ≥ 400, body is an object without `code`): add `code` from status and `request_id`; leave existing keys.
- Acceptance: `MailSettingsController` 422s, `IntegrationController::test` 422 and any other hand-built error keep their keys and gain `code` + `request_id`; responses that already have a `code` are not modified; non-JSON and 2xx untouched.
- Files: middleware, `bootstrap/app.php`, `tests/Feature/Api/SafetyNetTest.php`.

**T6. Request id in logs and the error contract documented** (S)

- Every logged exception (Laravel's reporter) and the AI warning include `request_id` via `Log::withContext`; `docs/ERRORS.md` describes the envelope, the code table, headers, examples and how the frontend should consume them (input for Phase 2b).
- Acceptance: a test asserts the log context contains the request id for a 500; docs match the implemented codes (a test reads the doc's code table and compares it with `ErrorCode`).
- Files: `docs/ERRORS.md`, `RequestId.php` (context), `tests/Feature/Api/ErrorLoggingTest.php`.

**T7. Final sweep and PR** (S)

- Grep for remaining `response()->json([...], 4xx|5xx)` and `abort(...)` call sites; list any not covered; update `tasks/todo.md`; verify the "no behavior change" boundary: diff of `app/` limited to error paths; fresh-clone `composer lint && composer test && composer test:scripts && composer audit`; push; open PR **S1**; STOP.

### Checkpoint B (final)

- [ ] CI green on the PR; every WART this slice fixes is flipped in the same commit as its fix
- [ ] Remaining WARTs (S2–S4) listed in the PR body
- [ ] Maintainer merges S1 before S2 is planned

## Risks and mitigations

| Risk                                                             | Impact | Mitigation                                                                                                                                                                                                                             |
| ---------------------------------------------------------------- | ------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| The frontend relies on today's status/messages                   | H      | S1 changes only: empty/leaky messages (now meaningful), AI failures 500→503/502, and additive fields. Frontend treats any status ≥ 500 as unavailable and uses `message` otherwise; checked against `lib/backend-api.ts` `safeMessage` |
| A global response middleware double-wraps or corrupts a body     | M      | Only JSON objects with status ≥ 400 and no `code`; tests for already-coded, non-JSON, 2xx                                                                                                                                              |
| Renderer hides a real bug during development                     | M      | 500s are always logged with exception + `request_id`; `APP_DEBUG` still shows Laravel's debug page for non-API routes                                                                                                                  |
| Dense controllers make edits error-prone                         | M      | Only 3 controller call sites are edited (login ×3, OTP mail, two 502s); characterization tests guard the rest                                                                                                                          |
| CSRF 419 and throttle errors occur before routing/session        | M      | Renderer is global; tests cover both                                                                                                                                                                                                   |
| `request_id` header on every response breaks nothing but caching | L      | Header only; no body change on success                                                                                                                                                                                                 |

## Open questions

1. Message wording in the catalog above (the frontend replaces some by status; `403`/`404`/`405` texts are new). OK as written?
2. `Retry-After: 30` for 503 upstream errors — fine?
3. `IntegrationController::test` and `MailSettingsController` keep `422` for provider connection failures in S1 (safety net adds `code: validation_failed`). That code is slightly misleading for "connection failed"; I recommend leaving it until S3 (when those endpoints get FormRequests/Resources) rather than inventing a code outside SPEC §4.2. OK?
4. The 401→403 split for suspended/unverified members and `204` on logout are not in S1 (S4 and S2) — confirm.
