# CVPilot AI - Laravel Backend

Production-oriented Laravel 12 + MySQL API for the CVPilot frontend.

## Included modules

- Email/password registration, verified accounts, OTP verification/reset, session rotation and logout.
- User profile and password change endpoints.
- Private PDF/DOCX/TXT CV uploads and text extraction.
- Local ATS scoring with keyword, section, readability and impact breakdowns.
- AI gateway for OpenAI, Anthropic, Gemini, Groq, Mistral and OpenRouter.
- Location-aware job search through JSearch, Adzuna and Jooble, plus eligible public remote feeds and country-specific LinkedIn/Indeed searches.
- Job match scores, direct employer URLs and LinkedIn/Indeed/Google fallback links.
- Application tracking states: saved, prepared, applied, interview, rejected and offer.
- Consent-based traffic analytics (visitors, sessions, page views, bounce rate, pages, sources, countries and devices).
- Admin summary, users, account suspension, security audit logs, encrypted API key management and connection testing.
- AI usage and failure logging without storing decrypted provider keys.

## Run with Docker

Requirements: Docker Engine with the Compose plugin.

1. On a fresh checkout, copy `.env.example` to `.env`. Keep any existing `APP_KEY` and credentials.
2. Set strong values for `DB_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `ADMIN_EMAIL` and `ADMIN_PASSWORD`.
3. Generate `APP_KEY` locally:
   `docker run --rm php:8.3-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`
4. For local Docker, set `DB_HOST=db`, `APP_URL=http://localhost:8080` and `FRONTEND_URL=http://localhost:5173`. Keep `SESSION_SECURE_COOKIE=false` and `SESSION_SAME_SITE=lax` locally. For production, set `APP_ENV=production`, use HTTPS domains, and use the session settings below.
5. Start the stack: `docker compose up -d --build`.
6. The container applies pending migrations automatically. Create or refresh the seeded admin with `docker compose exec api php artisan db:seed --force`.
7. Open `http://localhost:8080` locally or your HTTPS backend domain in production.

The API service binds to `127.0.0.1:8080`; put Nginx, Caddy or a hosting proxy with TLS in front of it. MySQL has no host port and must remain private.

## Connect the frontend

Set `NEXT_PUBLIC_BACKEND_URL` in the frontend root `.env` to the same value as backend `APP_URL`, without `/api`. Use `http://localhost:8000` for a local PHP server or `http://localhost:8080` for Docker Compose. Restart the frontend dev server or rebuild it for production. Sign in at `/login` with the seeded admin account; Dashboard > API keys > Backend Connection can display and test the configured URL.

The frontend never reads a backend URL from browser storage and has no fallback host. CORS allows only the origins configured in `FRONTEND_URL`, optional `FRONTEND_URL_LOCAL`, and `APP_URL`. Use the exact frontend origin including its port. After changing backend env values on a running deployment, run `php artisan config:clear` (or `docker compose exec api php artisan config:clear`) and restart the backend.

For local PHP development, use `DB_HOST=127.0.0.1`, configure your existing MySQL credentials, and run `php artisan serve --host=localhost --port=8000`. Open the frontend at `http://localhost:5173` so both sides use the same hostname.

Cross-site production sessions require:

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
| Arbeitnow | No key | Germany; remote listings only for worldwide remote searches |

The backend tries enabled job providers in priority order. Morocco searches send `country=ma`,
`language=fr` and the Moroccan city/country to JSearch. Work restricted to another country is excluded,
even when marked remote. Arbeitnow is skipped for Morocco, and Adzuna never substitutes US listings
for unsupported countries. Public remote feeds are considered for Morocco only when remote work is
explicitly requested, with location eligibility checked before result limits.

Job Apply starts in Morocco and provides prominent **LinkedIn Maroc** and **Indeed Maroc** searches
using the entered role and city. Indeed uses `ma.indeed.com`; LinkedIn receives the Moroccan location.
These links open the selected website. Imported offers inside CVPilot require a working job integration;
JSearch can aggregate publishers including LinkedIn and Indeed and preserves their source labels.
See [JSearch country targeting and publisher coverage](https://www.openwebninja.com/api/jsearch).
A failed provider is reported as unavailable rather than as proof that no jobs exist. An HTTP 403 from
JSearch requires checking the RapidAPI subscription and API key in Dashboard > API keys.

Job search regression checks: `php tests/jobs.php`. Frontend portal links: from the repository root,
run `node tests/job-links.mjs` with Node 22.13+ (native TypeScript stripping).

## SMTP email setup

Apply the SMTP OAuth migration after updating the backend: `php artisan migrate --force`.
Then run `php artisan config:clear` and rebuild the frontend. Docker startup applies migrations automatically.
Keep the existing `APP_KEY`: saved mail passwords and Microsoft tokens are encrypted with it.

Open **Dashboard > Settings > Email (SMTP) Dispatcher**. Select a provider, enter the sender account,
and use **Save & send test email**. This saves the displayed settings before testing and reports
connection, TLS, authentication, and sender errors without returning credentials. The test recipient
is the signed-in administrator. SMTP acceptance does not guarantee inbox placement; check spam too.
Blank secrets preserve saved credentials only for the same account/application. Changing the SMTP host
or username requires that account's password; changing Microsoft account/application settings requires reconnecting.

| Provider | Server | Port / encryption | Authentication |
|---|---|---|---|
| Brevo | `smtp-relay.brevo.com` | 587 or 2525 / STARTTLS, or 465 / SSL/TLS | Login and SMTP key from the Brevo SMTP tab; separate verified sender |
| Gmail / Google Workspace | `smtp.gmail.com` | 587 / STARTTLS, or 465 / SSL/TLS | Full email address and Google App Password |
| Outlook.com / Hotmail / Live | `smtp-mail.outlook.com` | 587 / STARTTLS | Microsoft sign-in (OAuth2) |
| Microsoft 365 work/school | `smtp.office365.com` | 587 / STARTTLS | Microsoft sign-in; SMTP password only where tenant policy allows |
| Custom SMTP | Provider's hostname | Provider's secure SMTP port | Provider credentials, or no username for an authorized relay |

### Brevo

Select **Brevo** in the SMTP form. In Brevo **Settings > SMTP & API > SMTP**, copy the exact
**Login** and an **SMTP key**. The key is the SMTP password; an API key or Brevo account password
will not work. Surrounding whitespace is removed when saving the key.

Enter a sender verified in Brevo, or an address on an authenticated sender domain. A technical
login ending in `@smtp-brevo.com` cannot be used as the sender. The form keeps these fields separate.
Previously saved invalid sender addresses must also be corrected.

Use **587 / STARTTLS**, **2525 / STARTTLS** if the host blocks 587, or **465 / SSL/TLS**.
For error **535**, verify the SMTP login/key. For **525**, authorize the backend's outbound IP
in Brevo. The dashboard now identifies these errors separately. If SMTP accepts the message but
it does not arrive, check Brevo transactional logs, sender verification, account activation,
sending credits and blocked contacts. See [Brevo SMTP troubleshooting](https://help.brevo.com/hc/en-us/articles/115000188150-Troubleshooting-Issues-with-Brevo-SMTP).

### Gmail

Enable 2-Step Verification, create an [App Password](https://myaccount.google.com/apppasswords),
and paste it into **Google App Password**. The usual account password is not accepted. Spaces from the
16-character App Password are removed automatically. Use the same mailbox for username and sender,
or an authorized sending alias. Workspace policies or Advanced Protection may prevent App Passwords;
see [Google's setup requirements](https://support.google.com/accounts/answer/185833).

### Outlook.com and Microsoft 365

1. Register an application in [Microsoft Entra](https://entra.microsoft.com/). To connect personal Outlook
   accounts, allow personal Microsoft accounts in its supported account types. Use tenant `common`,
   or your tenant ID/domain for a work/school-only application.
2. Add a **Web** redirect URI equal to `APP_URL/api/admin/smtp/microsoft/callback`.
   The SMTP form displays the complete URI. `APP_URL` must be the externally reachable backend URL
   (HTTPS in production), and `FRONTEND_URL` must identify the frontend for the return link.
3. Add the **Office 365 Exchange Online > Delegated permissions > SMTP.Send** permission.
   Create a client secret; copy its **value**, not the secret ID.
4. Select Outlook.com or Microsoft 365 in the SMTP form. Enter the full mailbox address, application ID,
   client secret, and tenant. Click **Save & connect Microsoft**, sign in to the same mailbox,
   and approve access. The application requests `SMTP.Send` and `offline_access` only.
5. Return to SMTP settings and send a test. Microsoft 365 may need administrator consent and
   **Authenticated SMTP** enabled on the mailbox. Sender aliases need Send As permission.

Authorization is bound to the administrator session and saved settings using state and PKCE, and expires
after ten minutes. Access and refresh tokens are encrypted and never returned to the browser.
Refresh tokens renew access automatically; reconnect if consent is revoked or the app credentials change.
The connection uses the SMTP XOAUTH2 authenticator. See
[Microsoft SMTP OAuth setup](https://learn.microsoft.com/en-us/exchange/client-developer/legacy-protocols/how-to-authenticate-an-imap-pop-smtp-application-by-using-oauth)
and [Outlook.com server requirements](https://support.microsoft.com/en-us/outlook/pop-imap-and-smtp-settings-for-outlook-com).

If the test times out, confirm the hosting provider allows outbound TCP on the SMTP port. For TLS errors,
check PHP OpenSSL support and certificate trust. Saving settings alone does not verify delivery.
Dashboard settings take precedence over `MAIL_*` environment values. The environment fallback honors
`MAIL_ENCRYPTION` (`tls` or `ssl`) and `MAIL_FROM_NAME`; set the matching port and run `php artisan config:clear`.

Use **Save & check connection** to verify the saved SMTP connection, TLS and configured login
without submitting an email. If that succeeds and **Save & send test email** fails, inspect the
sender/recipient or backend template/runtime diagnostic returned by the test.

Failed checks include an `smtp-…` reference. Search that reference in `storage/logs/laravel.log`
for the operation, exception type, source filename/line and numeric SMTP response code. These
entries intentionally exclude passwords, raw exceptions and SMTP transcripts. Deploy both the
frontend and backend for the new button and `/api/admin/smtp/check` endpoint.

SMTP regression checks: `php tests/smtp.php`. They use an in-memory SQLite database, fake Microsoft HTTP
responses, and intercepted mail delivery; they never send real emails or require provider credentials.

## Main endpoints

All routes are under `/api`. Protected routes require the encrypted session cookie and CSRF token from `GET /api/csrf`.

## BazaarLink AI

The API supports BazaarLink as an OpenAI-compatible AI provider. In the admin dashboard, create an AI integration with provider `bazaarlink`, paste a newly generated BazaarLink key, and enter an exact model ID available in your BazaarLink account. Requests are sent to `https://api.bazaarlink.ai/v1/chat/completions` and the key is stored encrypted in MySQL.

- `POST /register`, `POST /login`, `POST /logout`
- `POST /otp/request`, `POST /otp/verify`
- `GET|PATCH /me`, `POST /password`
- `GET|POST /cv`, `DELETE /cv/{id}`, `POST /cv/{id}/analyze`
- `POST /jobs/search`, `GET /jobs/links`
- `GET|POST /jobs/saved-searches`, `DELETE /jobs/saved-searches/{id}`
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
