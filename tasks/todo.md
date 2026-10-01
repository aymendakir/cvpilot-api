# Task list — Phase 3, slice S1 (`ats-language` + `ats-parsing`)

Branch `feature/ats-s1`. Detail: `tasks/plan.md`. Spec: `SPEC-ats.md` §2–§4, §7, §8.3, §17; input `docs/ats-spike-s0.md`.
Status: **planned; waiting for maintainer approval** (decisions 1–3 in the plan).

- [ ] T1 Tooling: poppler-utils + intl in Docker/CI, `wamania/php-stemmer`, `config/ats.php`, failing-if-missing test, DEPLOYMENT/README
- [ ] T2 `ats-language`: normalizer, tokenizer, stop words, stemmer, language detector; §8.3 stem facts
- [ ] T3 Parsing core, `TextParser`, `DocxParser`

### Checkpoint A

- [ ] Review with maintainer (DTOs, DOCX signals, stem facts)

- [ ] T4 Poppler wrapper and `PdfParser`
- [ ] T5 Fixture-wide parsing tests, time budget, temp-file check
- [ ] T6 Drop smalot (decision 1), delete the spike probe
- [ ] T7 Spec/docs, open the PR, **STOP**

### Checkpoint B (final)

- [ ] PR opened; S2 planned after merge
