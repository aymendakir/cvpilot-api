# AI-led CV review

Based on main ce031a76d546436e4e9d54a69a43208f437b2ada (user's latest ATS changes). Matching backend starts from a93d832b96560c1bd995fb94a35835b0343fb23c.

## Behavior
- Sign in before displaying results. File extraction is available before sign-in.
- AI assessment is the primary report: summary, three priorities, strengths with source quotes, explicit before/after line edits and four explained quality dimensions.
- Score is an **AI quality estimate**, not an employer ATS score, calibrated success probability or guarantee. Model judgements can vary. Four validated levels (0–4) produce round(sum / 16 * 100). Missing evidence/dimensions or under 80 words produces no score. No fabricated fallback score.
- Document checks remain deterministic and separate. Common English/French text headings are supported; this cannot establish visual PDF quality or extraction completeness.
- No offer is necessary. Job comparisons quote requirements from the supplied job and distinguish supported, partial and not found in CV. Absence in CV is not proof of missing ability.
- Suggested edits need acceptance, preserve numeric facts, and only modify a text draft. Source PDFs are unchanged; export draft as TXT or save the review with browser printing.
- AI failure has a visible retry state. New scans clear stale results.
- PDF reads use the actual parser or authenticated server extraction, not raw PDF stream regex extraction.

## Deploy
Deploy the backend update first, then the frontend. No database migration or new API key is required; the enabled AI provider is reused. Legacy job-fit callers still receive `answer`; structured callers receive validated `review`.

Frontend (Windows CMD):
```bat
set "NEXT_PUBLIC_BACKEND_URL=https://cvapi-7hmku.sevalla.app"
npm run build
npx wrangler deploy --config dist/server/wrangler.json
```

## Verification
- TypeScript and production build.
- `node tests/resume-audit.mjs`: malformed responses, score/rubric agreement, unsupported evidence statuses and legacy response handling.
- `node tests/resume-readiness.mjs`: rule-based checks.
- Simulated React interactions: upload, sign-in gate, tabs, accept edit, new CV, AI failure with no stale score, retry.
- PHP syntax parsed with php-parser. Native PHP was unavailable here; run `php tests/resume-review.php` and `php tests/resume-audit.php` in the backend container before production. Live provider output and physical iPhone/browser layout still require staging verification.

## Staging check
Use a synthetic CV, not customer data: verify login gating, AI success/failure, quote accuracy, edit acceptance, job/no-job behavior, 375px viewport, original PDF link, report print and actual iPhone file selection. Test a nontechnical and French CV as well as a developer CV. Avoid invented metrics and upgrades from assisting to leading.
