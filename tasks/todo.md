# Task list — Phase 2, slice S6 (ops: scheduler, deployment guide)

Branch `refactor/api-s6-ops`. Detail: `tasks/plan.md`. Spec: `SPEC.md` §7 item 6, §18 items 2 and 8.
Status: **plan written, waiting for maintainer approval and answers to 4 decisions. No code yet.**

- [ ] T1 Retention proven through the scheduler (files removed, fresh data kept, heartbeat)
- [ ] T2 Entrypoint roles, compose `scheduler` service, `api` healthcheck, CI checks
- [ ] T3 `TRUSTED_PROXIES` and the `admin/system` retention heartbeat

### Checkpoint A

- [ ] Review with maintainer

- [ ] T4 `docs/DEPLOYMENT.md`, README, `.env.example`, guide-vs-config test
- [ ] T5 SPEC note, fresh-clone checks, open the PR, **STOP**

### Checkpoint B (final)

- [ ] CI green; maintainer merges S6 before the cutover, S7 and Phase 2b are planned
