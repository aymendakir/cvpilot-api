# Task list — Phase 2, slice S1 (error envelope)

Branch `refactor/api-s1-error-envelope`. Detail and acceptance criteria: `tasks/plan.md`. Spec: `SPEC.md` §4.
Rules: test first (RED), minimal code (GREEN), flip the matching `WART` assertion in the same commit, `composer lint` + `composer test` + `composer test:scripts` before every commit. Stop at checkpoints.

- [x] T1 `RequestId` + `ForceJsonResponses` middleware; `X-Request-Id` on all responses; API 404 without `Accept` is JSON
- [x] T2 `ErrorCode`, `ApiException`, `ApiExceptionRenderer`: envelope for 400/401/403/404/405/409/413/419/422/429/500; no leaked internals; flip framework-error WARTs

### Checkpoint A (after T1–T2)

- [x] Every framework-originated error matches the envelope; suite green
- [ ] Review with maintainer (wording, `Retry-After`)

- [x] T3 Login (`invalid_credentials`, `account_suspended`, `email_not_verified`) and OTP mail failure carry specific codes; statuses and texts unchanged
- [x] T4 `UpstreamUnavailableException` (503) / `UpstreamInvalidResponseException` (502) in `AiGateway` and `AiController`; flip the three AI WARTs
- [x] T5 `EnsureErrorEnvelope` safety net for hand-built error bodies
- [x] T6 `request_id` in log context + `docs/ERRORS.md` (doc table checked against `ErrorCode` by a test)
- [x] T7 Sweep remaining hand-built errors, fresh-clone verification, open the S1 PR, **STOP**

### Checkpoint B (final)

- [x] CI green; all fixed WARTs flipped with their fix
- [x] Remaining WARTs for S2–S4 listed in the PR
- [ ] Maintainer merges S1 before S2 is planned
