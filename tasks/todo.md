# Task list — Phase 2, slice S3 (validation, Resources, Policies)

Branch `refactor/api-s3-validation`. Detail: `tasks/plan.md`. Spec: `SPEC.md` §5, §6, §11.
Status: **plan written, waiting for maintainer approval and answers to the 4 open decisions. No code yet.**

- [ ] T1 `ApiFormRequest`, `Limits`, audit-guard test (red)
- [ ] T2 Policies + IDOR matrix
- [ ] T3 FormRequests: auth
- [ ] T4 FormRequests: documents, applications, career records
- [ ] T5 FormRequests: AI and ATS (rules unchanged)
- [ ] T6 FormRequests: jobs, contact, analytics, library
- [ ] T7 FormRequests: admin (+ analytics `days` enum)
- [ ] T8 Data-provider validation tests

### Checkpoint A

- [ ] Guard allow-list empty; IDOR matrix green; review with maintainer

- [ ] T9 `UserResource` / `AdminUserResource`
- [ ] T10 Resources for owned models and admin lists; poisoned-model test
- [ ] T11 Provider failure codes 502/503
- [ ] T12 Admin CV text (per decision)

### Checkpoint B (final)

- [ ] CI green; legacy table unchanged; open S3 PR; STOP
