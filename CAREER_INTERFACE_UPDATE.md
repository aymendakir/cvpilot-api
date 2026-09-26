# Career interface, blog and administration update

This update extends the readiness/theme/mobile-PDF changes in PR #2. Deploy the backend before the frontend. Nothing has been deployed automatically.

## Features
- Homepage with an illustrative CV rewrite, workflow steps, latest published articles (guides when none exist).
- One desktop dropdown at a time; Escape, outside click, focus departure and mobile focus containment.
- Safe formatted AI answers across Tailor CV/Application Pack, Skill Gap, Career Analytics, Interview AI and the admin assistant. HTML from AI or blog authors is rendered as text.
- ATS observations: bullet markers, punctuation, repeated openings, reversed explicit year ranges, obvious broken URL text, file name and size. These are outside the 100-point readiness score. Spelling/grammar feedback uses the existing AI review. We do not simulate proprietary Enhancv scoring, employer parsing rates, peer comparisons or hiring probability.
- Public /blog and /blog/{slug}: server-rendered articles, metadata, canonical URLs, BlogPosting structured data and published-article sitemap entries (up to 1,200 posts). Guides remain available during list outages. SEO indexing still follows the existing website setting and requires a production site URL.
- Dashboard > Articles: create, edit, preview, publish, unpublish, delete and paginate articles. Drafts return 404 on public detail endpoints and never appear in the public list.
- Dashboard > Users > Create a user: client/admin role, confirmed password, explicit admin grant checkbox. Email verification defaults to required. An administrator can mark an independently verified address as verified. No credential email is sent. Passwords are hashed and hidden from API responses; actions are audited.

## Backend deployment
Run after the new image is running and database variables are configured:

```sh
php artisan migrate --force
php artisan optimize:clear
php artisan route:list --path=api/blog
php artisan route:list --path=api/admin/blog
php artisan route:list --path=api/admin/users
```
Do not regenerate an existing APP_KEY. The new migration creates blog_posts only. Publishing fails until the migration has run.

## Frontend deployment
Keep NEXT_PUBLIC_BACKEND_URL configured before building. On Windows, from the frontend root:

```sh
npm install --include=dev
npm run build
npx wrangler deploy --config dist/server/wrangler.json
```
The inherited npm lock file is out of sync: use npm install, review/commit its lock update locally, then use npm ci in subsequent builds.

## Verification
Executed: TypeScript noEmit, Vinext production build, existing 11 readiness cases plus 6 new diagnostic cases, 6 upload type cases. Browser visual validation was unavailable in this environment. PHP/Docker are unavailable here, so backend runtime and production smoke tests remain required.

Before exposing the features, test in staging:
1. Anonymous and client requests to admin blog and POST admin/users must fail; an admin must succeed with CSRF/session credentials.
2. Create a draft: absent from public list, public detail 404. Publish: visible. Unpublish: disappears. Duplicate/invalid slugs and invalid status return 422.
3. An article containing script tags or javascript links must show plain text, never execute.
4. Create an unverified client: password never returned; login requests verification. Admin creation without explicit confirmation fails. Registration/profile requests still cannot assign role.
5. Open the CV menu then Career menu; only one panel remains visible. Test Escape, keyboard Tab, touch drawer, 375px mobile, light and dark.
6. Upload a mobile PDF, view readiness evidence and verify the new observations do not change score weights.
7. Read a published article, verify its canonical production URL and sitemap entry. Delete it and verify the public URL is 404.
