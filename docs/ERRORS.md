# API errors

Every non-2xx response under `/api/*` (current paths and the future `/api/v1/*`) is JSON with the same envelope. Non-API routes (`/`, `/up`) are not covered.

```json
{
  "message": "The requested resource was not found.",
  "code": "not_found",
  "errors": { "email": ["The email field must be a valid email address."] },
  "request_id": "2e910dca-549a-4715-85b0-8f8dc6ad31c1"
}
```

| Field        | Always | Meaning                                                                                                                     |
| ------------ | ------ | --------------------------------------------------------------------------------------------------------------------------- |
| `message`    | yes    | Short neutral English text for logs and developers. **Clients must not display it**; map `code` to localized text instead.  |
| `code`       | yes    | Stable snake_case identifier from the table below.                                                                          |
| `errors`     | no     | Only for `validation_failed` raised by input validation: `{ "field": ["message", …] }`, dot-paths for nested fields.        |
| `request_id` | yes    | Equals the `X-Request-Id` response header. Quote it when reporting a problem; it is attached to every server-side log line. |

Rules the API guarantees:

- Never a stack trace, SQL, file path, class name, route name, provider response or API key in any field.
- 4xx `message` values are written by the application (or the standard text below). **5xx `message` is always the standard text**; the real cause is only in the server log, next to the `request_id`.
- API routes answer JSON whatever the `Accept` header says.
- A JSON request whose body is not valid JSON is rejected with `400 bad_request`.
- `Retry-After` is sent with `429` and `503` (`503`: 30 seconds). `Allow` is sent with `405`.
- Any other status maps to the nearest code: other 4xx → `bad_request`, `504` → `upstream_unavailable`, other 5xx → `server_error`.

## Codes

<!-- error-codes:start -->

| Status | Code                        | Standard message                                                             |
| ------ | --------------------------- | ---------------------------------------------------------------------------- |
| 400    | `bad_request`               | The request could not be understood.                                         |
| 401    | `unauthenticated`           | Authentication is required.                                                  |
| 401    | `invalid_credentials`       | Invalid credentials.                                                         |
| 403    | `forbidden`                 | You do not have permission to do this.                                       |
| 403    | `email_not_verified`        | Verify your email first.                                                     |
| 403    | `account_suspended`         | This account has been suspended by an administrator. Please contact support. |
| 404    | `not_found`                 | The requested resource was not found.                                        |
| 405    | `method_not_allowed`        | This method is not allowed for this endpoint.                                |
| 409    | `conflict`                  | The request conflicts with the current state.                                |
| 413    | `payload_too_large`         | The request is too large.                                                    |
| 419    | `csrf_mismatch`             | CSRF token mismatch.                                                         |
| 422    | `validation_failed`         | The given data was invalid.                                                  |
| 429    | `too_many_requests`         | Too many requests. Please wait and try again.                                |
| 500    | `server_error`              | Service temporarily unavailable.                                             |
| 502    | `upstream_invalid_response` | The service could not complete this request. Please retry.                   |
| 503    | `upstream_unavailable`      | The service is temporarily unavailable. Please try again later.              |

<!-- error-codes:end -->

Notes on specific codes:

- `unauthenticated`: no session, expired session, or a session revoked by a password change or suspension. (Today suspended and unverified members also get this on member routes; they become `403 account_suspended` / `email_not_verified` in a later slice.)
- `forbidden`: signed in but not allowed (for example a non-admin on `admin/*`).
- `not_found`: unknown route, unknown id, **and another user's resource** (the API never reveals that a record exists).
- `validation_failed`: for input validation, `message` is Laravel's summary and `errors` lists the fields. Domain rules (wrong OTP, wrong current password, unreadable document) use the same code and status **without** `errors`; the standard `message` is the developer text in that case.
- `upstream_invalid_response` (502): a provider answered but the output was unusable (for example an AI review that could not be parsed). Retrying may help.
- `upstream_unavailable` (503): a provider is unreachable, timed out, not configured, or all providers failed. Retry after `Retry-After`.
- `server_error` (500): unexpected failure. Show a generic message with the `request_id`.

## Consuming errors in the frontend

1. Read `code` first. Keep a table `code → localized text (FR/EN)`; fall back to a generic text per HTTP status for unknown codes (new codes can be added without a version bump).
2. For `validation_failed` with `errors`, show per-field messages mapped from field name (and rule where available), not the API text.
3. Show `request_id` as a support reference on `500`, `502` and `503`.
4. Retry only `503` (after `Retry-After`) and `502` (user-initiated). Never auto-retry `4xx`.
5. `401 unauthenticated` means "sign in again"; `403` does not.

## Adding or changing a code

Add it to `App\Exceptions\ErrorCode` (status and standard message), to the table above, and to the tests (`tests/Feature/Api/ErrorEnvelopeTest.php`). `ErrorDocsTest` fails if this table and the enum disagree. Raise it with `throw new ApiException(ErrorCode::…)`; use `UpstreamUnavailableException` / `UpstreamInvalidResponseException` for provider failures.

## ATS analysis: `errors.file` reasons

`POST /api/v1/ats/analyses` (SPEC-ats.md §5) refuses a file with `422 validation_failed` and **one stable token** in `errors.file` instead of a sentence, so the client can show its own EN/FR text. Other fields (`cv_text`, `job_description`, `locale`, `include_text`) keep the standard Laravel messages.

| Token                | Meaning                                                                                  |
| -------------------- | ---------------------------------------------------------------------------------------- |
| `missing`            | neither `file` nor `cv_text` was sent                                                    |
| `both_given`         | both `file` and `cv_text` were sent                                                      |
| `upload_failed`      | the upload did not arrive as a file (interrupted, or refused by PHP's upload limits)     |
| `too_large`          | the file is over 15 MB                                                                   |
| `unsupported_type`   | the content is not a PDF or DOCX (`.doc`, images, plain text, a renamed executable…)     |
| `password_protected` | the PDF needs a password                                                                 |
| `corrupt`            | the file is damaged or cannot be parsed                                                  |
| `timeout`            | reading the file took too long                                                           |

A scanned PDF is **not** refused: it returns `200` with `score_status: "unreadable"`. A request body over 20 MB is refused before validation with `413 payload_too_large`.

## Anonymous routes (API-A): `errors.file` and `errors.turnstile`

`POST /api/v1/public/ats/analyses` returns exactly the tokens above. `POST /api/v1/public/cv/extract` (reading a CV file without an account) returns `missing`, `upload_failed`, `too_large`, `unsupported_type`, `password_protected`, `corrupt`, `timeout`, plus:

| Token     | Meaning                                                                                   |
| --------- | ----------------------------------------------------------------------------------------- |
| `no_text` | the file opened but holds no readable text (fewer than 30 characters; a scanned PDF, for example) |

It accepts PDF, DOCX and plain text. The signed-in `cv-documents/extract` keeps its sentences.

Both anonymous routes check Cloudflare Turnstile first. A refused check is `422 validation_failed` with one token in `errors.turnstile`:

| Token     | Meaning                                                                    | Client                                  |
| --------- | -------------------------------------------------------------------------- | --------------------------------------- |
| `missing` | no `cf-turnstile-response` field or `CF-Turnstile-Response` header          | render the widget, then send its token   |
| `failed`  | Cloudflare rejected the token (expired, reused, wrong site)                | reset the widget and ask again           |

Cloudflare unreachable, or the API has no Turnstile secret outside local/testing: `503 upstream_unavailable`. The per-visitor and global limits answer `429 too_many_requests` with `Retry-After`.

