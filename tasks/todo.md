# Task list — Phase 3, slice S1 (`ats-language` + `ats-parsing`)

Branch `feature/ats-s1`. Detail: `tasks/plan.md`. Spec: `SPEC-ats.md` §2–§4, §7, §8.3, §17; input `docs/ats-spike-s0.md`.
Status: **T1–T7 built; S1 PR opened.** Checkpoint A approved (DTO shapes, symbol-bullet rule). Decisions: smalot removed in S1 behind a behaviour test; one-page PDF header/footer band = not detected; PDF image area from pdfimages (pixels ÷ ppi), medium, nothing written to disk.

- [x] T1 Tooling: poppler-utils + intl in Docker/CI, `wamania/php-stemmer`, `config/ats.php`, failing-if-missing test, DEPLOYMENT/README
- [x] T2 `ats-language`: normalizer, tokenizer, stop words, stemmer, language detector; §8.3 stem facts
- [x] T3 Parsing core, `TextParser`, `DocxParser`

### Checkpoint A

- [x] Review with maintainer (DTOs, DOCX signals, stem facts)

- [x] T4 Poppler wrapper and `PdfParser`
- [x] T5 Fixture-wide parsing tests, time budget, temp-file check
- [x] T6 Drop smalot (decision 1), delete the spike probe
- [x] T7 Spec/docs, open the PR, **STOP**

### Checkpoint B (final)

- [x] PR opened; S2 planned after merge
