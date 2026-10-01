# Task list — Phase 2, slice S7 (alias sunset)

Branch `refactor/api-s7`. Detail: `tasks/plan.md`. Spec: `SPEC.md` §9 (S7), §18 items 5 and 7.
Status: **T1–T5 built; S7 PR opened.** Pre-condition: Phase 2b (`cv-ai#16`) deployed and its smoke checklist passed.

- [x] T1 Port the tests and `tests/legacy` scripts to v1; add `LegacyPathsGoneTest`
- [x] T2 Remove `routes/legacy.php`, the `Deprecated` middleware and legacy config/CSRF/audit/header entries
- [x] T3 Delete legacy-only controller code (`logoutLegacy`, `CacheController::clear`, `improveCv`, `analyze`) and parity tests

### Checkpoint A

- [x] Review with maintainer (route table diff empty, ported vs deleted tests, decisions 1–3)

- [x] T4 Docs: ROUTES.md, README, `.env.example`, DEPLOYMENT, SPEC
- [x] T5 Fresh-clone checks, open the PR, **STOP**

### Checkpoint B (final)

- [x] PR opened; maintainer merges S7, then Phase 3 (`SPEC-ats.md`)
