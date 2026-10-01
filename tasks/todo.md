# Task list — Phase 2, slice S6 (ops: scheduler, deployment guide)

Branch `refactor/api-s6-ops`. Detail: `tasks/plan.md`. Spec: `SPEC.md` §7 item 6, §18 items 2 and 8.
Status: **plan updated for the no-persistent-disk finding; T1–T3 built; stopped at Checkpoint A.** Open: decisions 2, 3 (built as recommended, easy to drop), 5 (R2 or no file storage) and 6 (database cache).

- [x] T1 Retention proven through the scheduler (files removed, fresh data kept, heartbeat)
- [x] T2 Entrypoint roles, compose `scheduler` service, `api` healthcheck, CI checks
- [x] T3 `TRUSTED_PROXIES` and the `admin/system` retention heartbeat

### Checkpoint A

- [ ] Review with maintainer

- [ ] T4 Database sessions and cache in production
- [ ] T5 Logs to stderr in production
- [ ] T6 Uploaded files on the configured disk (R2) + stale-record command
- [ ] T7 `docs/DEPLOYMENT.md`, README, `.env.example`, guide-vs-config test
- [ ] T8 SPEC note, fresh-clone checks, open the PR, **STOP**

### Checkpoint B (final)

- [ ] CI green; maintainer merges S6 before the cutover, S7 and Phase 2b are planned
