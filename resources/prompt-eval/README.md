# Prompt evaluation inputs

Inputs for `php artisan cvpilot:prompt-eval` (S6, `SPEC-ats.md` §16.1). Everything here is fictional:

- `cv-en.txt`, `cv-fr.txt`: plain-text versions of the ATS fixture CVs (`tests/fixtures/ats/content/base-en.php`, `base-fr.php`).
- `job-en.txt`, `job-fr.txt`: copies of `tests/fixtures/ats/jobs/laravel-dev.txt` and `dev-symfony-fr.txt`.
- `job-en-injection.txt`: `job-en.txt` with a line that tries to give the model orders. With the envelope, the answer should ignore it.

Never put a real CV here.
