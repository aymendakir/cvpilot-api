# Task list — Phase 2, slice S3 (validation, Resources, Policies)

Branch `refactor/api-s3-validation`. Detail: `tasks/plan.md`. Spec: `SPEC.md` §5, §6, §11.
Status: **T1–T12 built; S3 PR opened. Waiting for the maintainer to merge before S4 is planned.**

- [x] T1 `ApiFormRequest`, `Limits`, audit-guard test (red)
- [x] T2 Policies + IDOR matrix
- [x] T3 FormRequests: auth
- [x] T4 FormRequests: documents, applications, career records
- [x] T5 FormRequests: AI and ATS (rules unchanged)
- [x] T6 FormRequests: jobs, contact, analytics, library
- [x] T7 FormRequests: admin (+ analytics `days` enum)
- [x] T8 Data-provider validation tests

### Checkpoint A

- [x] Guard strict (no allow-list); owner-access matrix green on v1 and legacy
- [x] Review with maintainer

- [x] T9 `UserResource` / `AdminUserResource`
- [x] T10 Resources for owned models and admin lists; poisoned-model test
- [x] T11 Provider failure codes 502/503
- [x] T12 Admin CV text (per decision)

### Checkpoint B (final)

- [x] Legacy table unchanged except the removed admin CV download; fresh-clone checks run; S3 PR opened; STOP
