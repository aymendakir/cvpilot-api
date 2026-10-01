# Implementation Plan: Phase 2, slice S5 — blog and `POST admin/users`

Spec: `SPEC.md` §8.1 (blog), §8.2 (`POST admin/users`), §18 items 3 and 10. S0–S4 are merged and deployed. This plan covers **S5 only**; S6 (scheduler, `docs/DEPLOYMENT.md`) and S7 (alias sunset) are planned afterwards, one at a time.

Branch: `refactor/api-s5-blog` (from `main` after S4). One PR. Stop after opening it.

## Overview

The deployed frontend already calls three endpoints that the API does not have yet, so blog pages, the admin blog editor, the sitemap's article list and the admin "Create a user" form all fail today. S5 adds them:

- `GET blog`, `GET blog/{slug}` (public, published posts only);
- `GET/POST admin/blog`, `GET/PUT/DELETE admin/blog/{id}` (admin);
- `POST admin/users` (admin creates an account).

One additive migration (`blog_posts`) is the only schema change; it is pre-approved in the spec.

## Current state (read, not changed)

- No blog table, model or routes. `PublicEndpointsTest` pins today's `404` for `/api/blog`, `/api/blog/some-post` and `/api/admin/blog` as a GAP that S5 flips.
- The frontend (`cv-ai` @ main) calls the **legacy** paths: `GET /api/blog?page=`, `GET /api/blog/{slug}` (server-side, `no-store`, 5 s timeout, also from `sitemap.ts` which pages through `last_page` up to 100 times), `GET/POST /api/admin/blog`, `PUT/DELETE /api/admin/blog/{id}` and `POST /api/admin/users`. Phase 2b moves it to `/api/v1`.
- The admin blog form limits: title ≤ 180, slug ≤ 180 matching `[a-z0-9]+(-[a-z0-9]+)*`, excerpt required ≤ 320, author ≤ 120, body 100–100 000 characters, status `draft` or `published`. The create-user form sends `name, email, password, password_confirmation, role, verified` and `confirm_admin` (only for admins), expects no email to be sent.
- S3/S4 give us the building blocks: FormRequests, Resources, the `admin` limiter (60/min) and the `admin.audit` middleware (every admin write is audited as `admin.<route name>` automatically), `ApiFormRequest::paginationRules()`/`perPage()`.
- `SecurityHeaders` sets `Cache-Control: no-store, private` on every `api/*` response, and the API runs inside the `web` middleware group, so every response starts a session and may carry a `Set-Cookie`.

## Decisions (recommendations; tell me if any is wrong)

1. **Legacy aliases for the new routes.** Because the deployed frontend calls the legacy paths, S5 registers legacy aliases next to the v1 routes (`GET api/blog`, `GET api/blog/{slug}`, `GET|POST api/admin/blog`, `GET|PUT|DELETE api/admin/blog/{post}`, `POST api/admin/users`), each with the usual `Deprecation`/`Link` headers. They are deleted in S7 with the others. Without them the live site stays broken until Phase 2b.
2. **`POST admin/users` returns `AdminUserResource`** (the spec says `UserResource`): it is a superset that also carries `suspended`, `last_seen_at` and `created_at`, which the admin list shows. The password and hashes never appear in either.
3. **Un-publishing keeps `published_at`.** It is set the first time a post is published and never reset, so a post that goes back to draft and is re-published keeps its original date. Public lists order by `published_at` descending.
4. **Public blog responses are cacheable and cookie-free** (`Cache-Control: public, max-age=60`, spec §8.1). That needs the two public routes to skip the session middleware, otherwise every response would carry a `Set-Cookie` and no cache could store it. They get their own per-IP limiter (60/min). All other API routes stay `no-store`.
5. **Body limits copy the form:** body 100–100 000 characters, excerpt ≤ 500 (column size; the form allows 320), title ≤ 180, slug ≤ 191 by the column but the rule uses the same regex as the spec.

## Architecture

- **Migration** `blog_posts`: `id, title(180), slug(191, unique), excerpt(500), body(text), author_name(120), status(20, default draft), published_at(nullable), timestamps`, index on `(status, published_at)`. Model `BlogPost` with `STATUSES = ['draft', 'published']` (single-sourced for the rules) and a `published()` scope.
- **Routes:** public (`v1.blog.index`, `v1.blog.show`, `whereNumber`-style slug constraint `[a-z0-9]+(?:-[a-z0-9]+)*`; an invalid slug is a plain `404`). Admin group: `v1.admin.blog.{index,store,show,update,destroy}` with `whereNumber('post')`. `POST v1/admin/users` as `v1.admin.users.store`, extra limiter `throttle:10,1,admin-create-user:`.
- **FormRequests** (`app/Http/Requests/Blog`, `Admin`): `ListPublishedPostsRequest` (`page`), `ListPostsRequest` (admin: `page`, `per_page`, optional `status`), `SavePostRequest` (shared by store and update; slug unique, ignoring the post being updated; status enum; all fields required because the form always sends them), `CreateUserRequest` (`name`, normalized `email` unique, `password` min 12 max 128 confirmed, `role` in user/admin, `verified` boolean, `confirm_admin` `accepted_if:role,admin`).
- **Resources:** `BlogPostResource` with exactly `{ id, title, slug, excerpt, body, author_name, status, published_at, updated_at }`; users via `AdminUserResource`. Public lists use `ModelResource::paginate()` so the paginator keys (`data`, `current_page`, `last_page`, …) match what `BlogPage` expects.
- **Controllers:** `BlogController` (public), `Admin\BlogPostController`, and `AdminController::storeUser`.
- **`POST admin/users` rules of engagement:** hashed password, `verified_at` only when `verified` is true, `role` set explicitly (never mass-assigned from the request), no email sent, audited by the middleware plus a specific `user_created:<id>` event (and `admin_account_created:<id>` when the role is admin). The new account signs in like any other (unverified accounts must verify first).
- **Contract bookkeeping:** `routes-v1.json`, `docs/ROUTES.md`, the contract test's `LATER_SLICES` list, and the pinned GAP test in `PublicEndpointsTest` are updated in the same commits as the routes.

## Task list

Rules for every task: write the test first, flip a characterization test only in the commit that changes the behaviour, `composer lint` + `composer test` + `composer test:scripts` before each commit.

- **T1** Migration, `BlogPost` model and `BlogPostResource`; migration test (table, unique slug, index) and a resource shape test.
- **T2** Public `GET blog` and `GET blog/{slug}` (v1 + legacy alias): published only, newest first, 12 per page, drafts and unknown slugs `404`, `Cache-Control: public, max-age=60`, no `Set-Cookie`, per-IP limiter; flips the GAP test for `/api/blog`.
- **T3** Admin blog CRUD (v1 + legacy aliases): create `201`, update, delete `204`, list with drafts, `published_at` rules, duplicate slug `422 validation_failed` (also on update, but not against itself), invalid slug/status/length `422`, body stored and returned as plain text (HTML is not interpreted or stripped), audit rows via the middleware; flips the GAP test for `/api/admin/blog`.

### Checkpoint A (after T3)

Show: the blog contract against the frontend's `BlogPost`/`BlogPage` types, the cache and cookie headers, the slug and `published_at` behaviour. Review with maintainer.

- **T4** `POST admin/users` (v1 + legacy alias): `201` with `AdminUserResource`, role and `verified` handling, `confirm_admin` rule, duplicate email `422`, password never returned or logged, audit events, limiter; a created admin or user can sign in (verified) or is told to verify (unverified).
- **T5** Contract bookkeeping and docs: update `routes-v1.json`, regenerate `docs/ROUTES.md`, remove the S5 entries from `LATER_SLICES`, SPEC §11 item for S5, `docs/ERRORS.md` untouched (no new codes).
- **T6** Fresh-clone checks (`composer lint`, `composer test`, `composer test:scripts`, `route:cache`, `composer audit`), open the PR with a deploy note (the migration runs from the entrypoint) and **STOP**.

### Checkpoint B (final)

- [ ] CI green; legacy table = baseline + the new aliases only
- [ ] Every v1 route matches SPEC §3.2 including blog and `POST admin/users`
- [ ] Maintainer merges S5 before S6 is planned

## Risks

| Risk | Mitigation |
| --- | --- |
| Public blog routes leak a session cookie or get cached with one | Routes skip the session middleware; test asserts no `Set-Cookie` and the exact `Cache-Control` |
| A draft becomes publicly readable | One `published()` scope used by both public routes; tests for list, show and sitemap-style paging |
| Stored article body is rendered as HTML somewhere | API returns it as JSON text with `nosniff`; test pins that HTML is stored verbatim; the frontend renders text (`AiResponse`) |
| `POST admin/users` creates an admin by accident | `confirm_admin` must be exactly `true` for `role=admin`; audited under two events; limiter 10/min |
| Duplicate slug race between two saves | Validation rule plus the database unique index; a unique-violation maps to the same `422` |
| The sitemap loops on `last_page` | Paginator always reports `last_page >= 1`; test with zero, one and many posts |

## Out of scope

Blog images, tags, categories (decision 10), comments, scheduled publishing, user invitation emails, the scheduler and `DEPLOYMENT.md` (S6), alias removal (S7).
