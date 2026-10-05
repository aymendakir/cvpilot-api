# Implementation Plan: API-D — anonymous AI routes (`cv-ai/SPEC.md` §16, §18.3)

Why: the landing pages of four tools run them inline once the API has anonymous AI routes (§18.3: cover letter, recruiter view, skill gap, follow-up message). The SPEC orders these after S6; S6 is merged and its evaluation was accepted on 2026-10-05 (`AI_PROMPT_ENVELOPE=true`). The S6 plan is in git history.

Branch: `feat/api-d-public-ai` (from `main`). One PR.

## Routes

Under the existing `public` group (no session, no cookie, no CSRF; API-A decision D5):

| Route | Input (same rules as the signed-in route) | Prompt |
| --- | --- | --- |
| `POST /api/v1/public/ai/cover-letter` | `CoverLetterRequest` | `AssistantPrompts::coverLetter` |
| `POST /api/v1/public/ai/recruiter-view` | `DocumentsRequest` | `AssistantPrompts::recruiterView` |
| `POST /api/v1/public/ai/skill-gap` | `DocumentsRequest` | `AssistantPrompts::skillGap` |
| `POST /api/v1/public/ai/follow-up` | `FollowUpRequest` | `AssistantPrompts::followUp` |

- Answer: `{"answer": "..."}` only (no report id, no provider or model).
- **Always the envelope**, whatever `AI_PROMPT_ENVELOPE` says: the text comes from anyone.
- Portfolio review is not included: it fetches GitHub on the visitor's behalf.

## Limits and budget

One limiter, `public-ai`, shared by the four routes (a visitor's budget covers all tools):

- per visitor (keyed hash of the address, as API-A): `PUBLIC_AI_PER_MINUTE` (default 3) and `PUBLIC_AI_PER_DAY` (default 10);
- all visitors together: `PUBLIC_AI_GLOBAL_PER_DAY` (default 300; `0` = no cap) — the daily cost ceiling;
- `PUBLIC_AI_ENABLED` (default `true`): `false` answers `503` on the four routes at once, without a deploy of the frontend.

Order: limits, then Turnstile, then validation, then the model call. `429` carries `Retry-After`.

## Privacy

- Nothing is stored: no report, no admin copy, no audit line. The only row is the existing `ai_usage` line (provider, model, feature `public_*`, latency, success; `user_id` null), which holds no text and no address.
- Logs hold no CV text, job text, names or address (test with a sentinel, as API-A).

## Tasks

1. Config (`anonymous.php`), limiter, `EnsurePublicAiEnabled` check, routes, `PublicAccess\AiController`.
2. Tests: same validation as the signed-in routes; prompt always enveloped; answer shape; per-minute, daily and global limits; Turnstile first; switch off = 503; nothing stored, nothing logged; no cookie.
3. Docs: `.env.example`, `docs/DEPLOYMENT.md` (env table, section 10).
4. Pint, full suite.

## After this PR

cv-ai M7: inline tools on the four landing pages.
