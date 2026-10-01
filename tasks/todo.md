# Task list — Phase 2, slice S2 (routing and `/api/v1`)

Branch `refactor/api-s2-routing`. Detail and acceptance criteria: `tasks/plan.md`. Spec: `SPEC.md` §3.
Rules: test first, minimal change, **legacy responses unchanged**, `composer lint` + `composer test` + `composer test:scripts` before every commit. Stop at checkpoints.

- [x] T1 Pin the current route table (fixture + `LegacyRoutesTest`)
- [x] T2 Infrastructure: `routes/api.php` + `routes/legacy.php`, `Deprecated` middleware, `auth.session` alias, closures → controllers, controller move to `Api\V1` (mechanical commit)
- [x] T3 v1 public, auth and account routes; logout 204 (legacy 200 shim); parity tests

### Checkpoint A (after T2–T3)

- [x] Legacy table identical to the baseline; deprecation headers; first v1 slice with parity tests
- [ ] Review with maintainer

- [ ] T4 v1 cv-documents, applications (+show), jobs, ai, ats; legacy-only improve-cv / analyze
- [ ] T5 Split `CareerController` (1/2): versions, workspaces, reports, library, insights
- [ ] T6 Split `CareerController` (2/2): AI generators, interviews; delete `CareerController`
- [ ] T7 v1 admin routes; warnings 201, `DELETE admin/cache` 204 (legacy shims); callback fixed
- [ ] T8 Contract test, `route:cache` in CI, `docs/ROUTES.md`
- [ ] T9 Fresh-clone verification, open the S2 PR, **STOP**

### Checkpoint B (final)

- [ ] CI green; legacy table = baseline + names/deprecation only
- [ ] Every v1 route matches SPEC §3.2; `route:cache` works
- [ ] Maintainer merges S2 before S3 is planned
