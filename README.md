# CVPilot AI - Laravel Backend

Production-oriented Laravel 12 + MySQL API for the CVPilot frontend.

## Included modules

- Email/password registration, verified accounts, OTP verification/reset, session rotation and logout.
- User profile and password change endpoints.
- Private PDF/DOCX/TXT CV uploads and text extraction.
- Local ATS scoring with keyword, section, readability and impact breakdowns.
- AI gateway for OpenAI, Anthropic, Gemini, Groq, Mistral and OpenRouter.
- Job gateway for JSearch, Adzuna, Jooble and a no-key Arbeitnow fallback.
- Job match scores, direct employer URLs and LinkedIn/Indeed/Google fallback links.
- Application tracking states: saved, prepared, applied, interview, rejected and offer.
- Consent-based traffic analytics (visitors, sessions, page views, bounce rate, pages, sources, countries and devices).
- Admin summary, users, account suspension, security audit logs, encrypted API key management and connection testing.
- AI usage and failure logging without storing decrypted provider keys.

## Run with Docker

Requirements: Docker Engine with the Compose plugin.

1. Copy `.env.example` to `.env`.
2. Set strong values for `DB_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `ADMIN_EMAIL` and `ADMIN_PASSWORD`.
3. Generate `APP_KEY` locally:
   `docker run --rm php:8.3-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`
4. Set `APP_URL` to the backend HTTPS domain and `FRONTEND_URL` to the frontend domain.
5. Start the stack: `docker compose up -d --build`.
6. The container applies pending migrations automatically. Create or refresh the seeded admin with `docker compose exec api php artisan db:seed --force`.
7. Open `http://127.0.0.1:8080` locally or your HTTPS backend domain in production.

The API service binds to `127.0.0.1:8080`; put Nginx, Caddy or a hosting proxy with TLS in front of it. MySQL has no host port and must remain private.

## Connect the frontend

Open `/dashboard`, choose **API keys**, save the deployed Laravel URL, and verify it. Sign in at `/admin/login` with the seeded admin account. Cross-origin sessions require:

- `FRONTEND_URL=https://your-frontend-domain`
- `APP_URL=https://your-api-domain`
- `SESSION_SECURE_COOKIE=true`
- `SESSION_SAME_SITE=none`
- HTTPS on both origins

## Configure providers

From Dashboard > API keys or the backend console:

### AI providers

| Provider | Example model |
|---|---|
| OpenAI | `gpt-5-mini` |
| Anthropic | `claude-sonnet-4-5` |
| Gemini | `gemini-2.5-flash` |
| Groq | `llama-3.3-70b-versatile` |
| Mistral | `mistral-small-latest` |
| OpenRouter | `openai/gpt-4.1-mini` |

Model identifiers change over time; use a model enabled on your provider account. Keys are encrypted using `APP_KEY` and are never returned by read endpoints.

### Job providers

| Provider | Configuration | Best use |
|---|---|---|
| JSearch | RapidAPI key | Worldwide search |
| Adzuna | API key + App ID | Supported country indexes |
| Jooble | API key | Broad international search |
| Arbeitnow | No key | European and remote fallback |

The backend selects the newest enabled job provider unless the request names one. When no configured job provider exists, it uses the backend-side Arbeitnow fallback, avoiding browser CORS failures.

## Main endpoints

All routes are under `/api`. Protected routes require the encrypted session cookie and CSRF token from `GET /api/csrf`.

## BazaarLink AI

The API supports BazaarLink as an OpenAI-compatible AI provider. In the admin dashboard, create an AI integration with provider `bazaarlink`, paste a newly generated BazaarLink key, and enter an exact model ID available in your BazaarLink account. Requests are sent to `https://api.bazaarlink.ai/v1/chat/completions` and the key is stored encrypted in MySQL.

- `POST /register`, `POST /login`, `POST /logout`
- `POST /otp/request`, `POST /otp/verify`
- `GET|PATCH /me`, `POST /password`
- `GET|POST /cv`, `DELETE /cv/{id}`, `POST /cv/{id}/analyze`
- `POST /jobs/search`, `GET /jobs/links`
- `GET|POST|PATCH|DELETE /applications`
- `POST /ai/chat`, `/ai/improve-cv`, `/ai/ats-analysis`, `/ai/cover-letter`
- `GET|POST /career/workspaces`, `GET|POST|DELETE /career/cv-versions`
- `POST /career/tailor-cv`, `/career/recruiter-view`, `/career/application-pack`
- `POST /career/interviews`, `/career/interviews/{id}/reply`, `/career/interviews/{id}/finish`
- `POST /career/skill-gap`, `/career/portfolio`, `/career/follow-up`, `/career/diagnostic`
- `POST /analytics/events` (public, consent required)
- `GET /admin/summary`, `/admin/users`, `/admin/logs`
- `GET /admin/analytics?days=7|30|90`
- `GET|POST /admin/integrations`
- `POST /admin/integrations/{id}/test`, `DELETE /admin/integrations/{id}`

## Production checklist

- Keep `.env`, `APP_KEY`, database backups and provider keys outside Git.
- Set `APP_DEBUG=false`; serve only HTTPS.
- Add SMTP credentials and test OTP delivery.
- Test registration, login, verification, password reset and session revocation.
- Test PDF/DOCX extraction with real documents; scanned PDFs need OCR before upload.
- Test every enabled AI/job provider and set spending/rate limits at the provider.
- Run `composer audit`; update dependencies regularly.
- Configure backups, log rotation, retention and monitoring.
- Set a retention policy for `traffic_events` and `audit_events`; country data is approximate and analytics never stores raw visitor IP addresses.
- Never expose MySQL or the Laravel admin console without authentication.

## Workspace and administration update

Deploy both the frontend and this backend, then run `php artisan migrate --force`.
The migration adds template designs, encrypted SMTP settings, temporary administrator review copies, upload expiration, and editable CV data. It imports review copies only for work updated during the previous 48 hours. Keep the existing `APP_KEY`; changing it makes existing encrypted secrets unreadable.

- **Profile / My saved work**: paginated CV versions, interviews, job workspaces, reports, letters, applications and unexpired uploads. CVs reopen for editing; interviews can resume.
- **Dashboard / CV templates**: edit example content, layout, accent, typography and spacing. Save drafts or publish to the public CV Builder. Use fictional example details: published templates are public.
- **Dashboard / SMTP email**: set host, port, username, password, sender address/name and TLS mode. Blank password preserves the saved secret. The test button sends to the signed-in administrator. Existing environment SMTP remains the fallback until dashboard settings are saved.
- **Dashboard / Users**: search, inspect activity and recent work, block/unblock, or send a reviewed warning email. Blocking revokes existing sessions. Users must sign in again after unblocking.
- **Dashboard / Applications**: recent application review copies, including notes and linked CV version IDs.
- **Dashboard / API keys**: edit, rotate, enable/disable, test or delete an integration. Empty key input preserves its existing secret.

### 48-hour retention

Original uploaded CV files, their extracted upload text, and administrator review copies expire after 48 hours. Endpoints reject expired files/copies immediately. A scheduled task removes expired files and database rows every minute. User-saved CVs, workspaces, interviews, applications and reports are deliberately preserved. Editing user work creates a fresh review window for that updated copy. Account activity/totals are operational metadata and are not temporary content copies.

The provided Docker web entrypoint starts `php artisan schedule:work` automatically (`RUN_SCHEDULER=true` by default). On other hosting, run a persistent scheduler process or add this cron entry:

```cron
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

Manual check: `php artisan cvpilot:prune-temporary`. Run it only against the intended environment. Database/file backups require a separate hosting retention policy.

### Regression checks

```sh
composer install
php tests/workspace.php
```

This standalone integration check creates an in-memory SQLite database and temporary files. It never connects to the production database and fakes AI and email delivery. It covers ownership, independent auth limits, persistence, encrypted secrets, blocking, templates, and expiry while preserving saved user work.

After deployment, configure SMTP and use **Send test to my email**, then verify a real registration/reset flow. AI/provider connectivity needs your own provider credentials and is tested through each integration's **Test** button.
