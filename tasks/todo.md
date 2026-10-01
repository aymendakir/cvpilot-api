# Task list — Phase 3, slice S0 (PDF spike and fixtures)

Branch `feature/ats-s0`. Detail: `tasks/plan.md`. Spec: `SPEC-ats.md` §4.1, §8, §12 R1, §16, §17.
Status: **T1–T3 built; at Checkpoint A.** Decisions: real PDFs arrive before T4 (local only, deleted after the spike); throwaway spike script; hand-written encrypted PDF.

- [x] T1 `setasign/fpdf` (dev), BASE-EN/BASE-FR content, `build.php`, clean fixtures
- [x] T2 Problem variants, corrected variants, error-case files
- [x] T3 Jobs, `expected/*.json`, `manifest.json`, `FixturesTest`

### Checkpoint A

- [ ] Review with maintainer (fixtures, FR text, expected values)

- [ ] T4 Spike probe on generated (and real) PDFs
- [ ] T5 `docs/ats-spike-s0.md`, open the PR, **STOP**

### Checkpoint B (final)

- [ ] PR opened; maintainer decides smalot vs poppler; S1 planned after merge
