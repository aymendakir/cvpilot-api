# ATS review API update

Deploy this backend before the corresponding CV-only frontend report.

`POST /api/ai/ats-analysis` keeps the existing authentication and throttling. `cv_text` is still required; `job_description` can now be omitted or null. A supplied description must still contain at least 60 characters.

With `report_format: structured`, the response is `{ "review": { "summary": "...", "suggestions": [...] } }`. Suggestions must reference an exact CV line; unsupported new numeric values are filtered. All AI wording still requires user review: numeric and source-line validation cannot establish the truth of every semantic change.

Legacy requests without `report_format` continue receiving the gateway's `answer` response. No database changes or new credentials are needed. Missing providers or malformed AI responses do not produce fake reviews; the frontend preserves the independently computed document checks.

Run `php tests/resume-review.php` and PHP syntax checks before deployment. PHP was unavailable in the editing environment, so these backend checks were added but not executed there. Verify authenticated CV-only requests, optional job comparison, absent providers, malformed responses, and existing legacy callers in staging.
