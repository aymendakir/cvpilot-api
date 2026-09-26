# ATS review API update

Deploy this backend before the corresponding CV-only frontend report.

`POST /api/ai/ats-analysis` keeps the existing authentication and throttling. `cv_text` is still required; `job_description` can now be omitted or null. A supplied description must still contain at least 60 characters.

With `report_format: structured`, the response is `{ "review": { "summary": "...", "suggestions": [...] } }`. Suggestions must reference an exact CV line; unsupported new numeric values are filtered. All AI wording still requires user review: numeric and source-line validation cannot establish the truth of every semantic change.

Legacy requests without `report_format` continue receiving the gateway's `answer` response. No database changes or new credentials are needed. Missing providers or malformed AI responses do not produce fake reviews; the frontend preserves the independently computed document checks.

Run `php tests/resume-review.php` and PHP syntax checks before deployment. PHP was unavailable in the editing environment, so these backend checks were added but not executed there. Verify authenticated CV-only requests, optional job comparison, absent providers, malformed responses, and existing legacy callers in staging.


## Mobile PDF fallback

`POST /api/cv/extract` accepts an authenticated multipart PDF (file field, up to 10 MB), validates it, extracts text and returns a private no-store response. It does not create a CvDocument record, retain a file, log CV text or send it to AI. The endpoint is limited to six requests per minute using the existing member and API middleware. Parser exceptions receive a generic safe message; scans require OCR, which this endpoint does not provide.

The Docker image now explicitly configures upload_max_filesize=10M, post_max_size=12M and memory_limit=256M. Rebuild the image for these settings to take effect. On other PHP hosts configure equivalent limits. No migration is required.

Runtime verification needed in staging: valid PDF, empty/scanned/encrypted/malformed PDF, over-limit file, unauthenticated request and rate limiting. PHP is unavailable in this workspace, so no backend execution claim is made.
