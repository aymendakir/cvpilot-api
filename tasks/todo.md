# Task list — Phase 2, slice S4 (security and auth)

Branch `refactor/api-s4-security`. Detail: `tasks/plan.md`. Spec: `SPEC.md` §6, §7, §18.
Status: **T1–T9 built; S4 PR opened.** Decisions answered: explicit SESSION_SAME_SITE=none before deploy, prompt envelope moved to Phase 3, admin audit via `admin.<route name>`.

- [x] T1 Session hardening and production boot guard
- [x] T2 `Member` → `AuthSession`; 401/403 split
- [x] T3 Throttles: login 5/30, admin 60/min
- [x] T4 Headers and CORS

### Checkpoint A

- [x] Review with maintainer

- [x] T5 Register enumeration (always 201, notice email)
- [x] T6 Uniform admin audit middleware
- [x] T7 `AdminReview` stops storing CV text, purge migration
- [x] T8 Redaction, CSRF-exemption and audit pins
- [x] T9 Docs, fresh-clone checks, open the PR, **STOP**

### Checkpoint B (final)

- [x] Deploy note at the top of the PR; maintainer merges S4 before S5 is planned
