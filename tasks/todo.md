# Task list — Phase 2, slice S4 (security and auth)

Branch `refactor/api-s4-security`. Detail: `tasks/plan.md`. Spec: `SPEC.md` §6, §7, §18.
Status: **plan written, waiting for maintainer approval and answers to 3 decisions. No code yet.**

- [ ] T1 Session hardening and production boot guard
- [ ] T2 `Member` → `AuthSession`; 401/403 split
- [ ] T3 Throttles: login 5/30, admin 60/min
- [ ] T4 Headers and CORS

### Checkpoint A

- [ ] Review with maintainer

- [ ] T5 Register enumeration (always 201, notice email)
- [ ] T6 Uniform admin audit middleware
- [ ] T7 `AdminReview` stops storing CV text, purge migration
- [ ] T8 Redaction, CSRF-exemption and audit pins
- [ ] T9 Docs, fresh-clone checks, open the PR, **STOP**

### Checkpoint B (final)

- [ ] CI green; deploy note in the PR; maintainer merges S4 before S5 is planned
