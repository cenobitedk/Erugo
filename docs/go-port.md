# Go port

Replace the Laravel HTTP process with a Go server. The Vue app, tusd, Caddy, the SQLite schema, and the files on disk stay.

The HTTP router is [chi](https://github.com/go-chi/chi). OpenAPI is out of scope. Google, Microsoft, Authentik, and generic OIDC wait until after this series.

## How the branches work

`cursor/go-port-fa3b` is the integration branch. Each slice below is its own branch, named `cursor/go-port-<slice>-fa3b`, and its pull request targets `cursor/go-port-fa3b`.

Merge them in the order listed. Start a slice from the integration branch after the previous slice has merged. The PHP app keeps serving until the cutover slice.

Go code lives in `go/` so both servers can exist in the tree. The Vue sources stay where they are.

## What stays compatible

The Vue client and an existing install have to keep working without a frontend change.

- Paths, methods, and status codes stay as they are in `routes/api.php` and `routes/web.php`.
- JSON responses keep the envelope `{ "status", "message", "data" }`. Validation failures stay `422` with `data.errors`.
- Passwords stay bcrypt. Laravel writes `$2y$` hashes; `golang.org/x/crypto/bcrypt` verifies those.
- Access tokens stay HS256, signed with `JWT_SECRET`. The claims the UI reads are `sub`, `admin`, `exp`, `must_change_password`, and `guest`. `guest` is a boolean. The UI compares it with `== 1`, which is true for boolean `true`.
- Access-token lifetime is `JWT_TTL` minutes (default 60). `expires_in` in the login body is that lifetime in seconds.
- The refresh token is the `refresh_token` cookie: HttpOnly, not encrypted, `SameSite=Lax`, `Secure` off so plain HTTP still works. `AuthController` names the lifetime "twenty four hours" but passes `86400` to APIs that take minutes, so the cookie and the refresh JWT both last 86400 minutes. Match that. Do not shorten it in this port.
- The database file is the one in `DB_DATABASE`. Production Docker defaults to `storage/app/private/database.sqlite`. Dev Docker defaults to `storage/database/database.sqlite`.
- Share bytes stay under `storage/app/shares/<user>/<long_id>`. tusd keeps writing to `storage/app/uploads`. Backgrounds, logos, and favicons stay in their current directories.
- `GET /shares/{id}` still returns HTML for a browser and the file for curl, wget, and HTTPie.

The Go process opens the existing SQLite file. It does not create a new schema and it does not run Laravel migrations. Tests use a SQL snapshot of the current tables, checked in under `go/internal/store/testdata/`.

New tokens only have to satisfy the Vue client. Accepting a token that PHP already signed is not required.

## Out of scope

- OIDC login, account linking, and the auth-provider admin API. The login screen calls `GET /api/available-auth-providers` with no error handler, so that route returns an empty list until the follow-up. Configured providers stop appearing on the login page. Password login keeps working.
- `web-auth/webauthn-lib`. Nothing in `app/` calls it.
- Replacing tusd, Caddy, or the Vue app.
- A job table. Zip builds and mail run in the Go process. The Laravel `jobs` table can sit unused.

## Layout

```
go/
  cmd/erugo/main.go
  internal/config/
  internal/httpapi/     chi router, envelope, middleware
  internal/store/       database/sql queries
  internal/auth/
  internal/spa/
  internal/share/
  internal/upload/
  internal/mail/
```

Use `database/sql` and `modernc.org/sqlite` (no cgo). Queries stay as SQL next to the code that uses them.

## Slices

Each slice is done when `go test ./...` passes and the routes it adds match the PHP responses for the cases listed. The PHP server is still the one Caddy talks to until slice 12.

### 1. Skeleton — `cursor/go-port-skeleton-fa3b`

Module, config from the environment, chi, structured logging, SQLite open, and the JSON envelope helper.

`GET /api/health` returns the current body, including `data.max_share_size` from settings.

### 2. Auth — `cursor/go-port-auth-fa3b`

`POST /api/setup` (refuses once any user exists), `POST /api/auth/login`, `POST /api/auth/refresh`, `POST /api/auth/logout`.

JWT middleware for routes that use `auth` today. Admin middleware for routes that use both.

Login verifies a hash produced by Laravel. Refresh reads the cookie. Logout clears it.

`GET /api/available-auth-providers` returns an empty list.

### 3. Shell — `cursor/go-port-shell-fa3b`

Serve the Vite build from `public/build`. HTML for `/`, `/reset-password/{token}`, and `/shares/{id}`, with the same `data-settings` payload and theme CSS variables as `resources/views/app.blade.php`.

Public reads the shell needs: active theme, favicon, background list and image bytes, and the settings keys `web.php` injects (`ui*`, language, reverse-share flag, expiry defaults, setup-needed, `api_url`, version).

### 4. Share read and download — `cursor/go-port-share-download-fa3b`

`GET /api/shares/{id}`, `GET` and `POST` download of the whole share, and download of one file including nested paths.

Expiry and download-limit responses stay `410`. A wrong password stays `401`. A missing or private share stays `404`. `X-Share-Password` and the `password` field both work.

Path checks reject traversal. The browser gets the HTML shell; curl, wget, and HTTPie get the file, including `/shares/{id}/{filename}`.

### 5. Share management — `cursor/go-port-share-manage-fa3b`

Authenticated: my shares, expire, extend, download limit, prune. Admin: all shares.

List rows keep the appended fields the UI reads (`expired`, `deletes_at`, `deleted`, file count). `path` and `user_id` stay hidden.

### 6. Uploads — `cursor/go-port-uploads-fa3b`

tusd stays a separate process. This slice is the hook handler and share creation.

- `POST /api/tusd-hooks` for `pre-create`, `post-create`, `post-finish`, and `post-terminate`.
- `pre-create` checks the bearer token and the max upload size, including the running total.
- `post-finish` writes the file row, and unpacks a bundle zip (`__erugo_manifest__.json`) with the same path checks.
- Reject hooks whose source address is outside localhost and the private ranges the PHP handler allows.
- `GET /api/uploads/verify/{id}` and `POST /api/uploads/create-share-from-uploads`.
- Move uploaded bytes into the share directory, then build the multi-file zip off the request. A restart mid-zip leaves the share unfinished; the next pass rebuilds a missing zip instead of depending on the Laravel `jobs` table.

### 7. Admin settings and branding — `cursor/go-port-settings-fa3b`

Read and write settings, logo, favicon, backgrounds (upload, thumb, delete, WebP conversion), and themes (list, save, delete, install, set active).

### 8. Users — `cursor/go-port-users-fa3b`

Profile get and update. Admin list, create, update, delete, and force password reset.

Create and force-reset call the mail interface. Until slice 9 that interface records the message and does not send it. Unlink-provider deletes a `user_auth_providers` row; it does not add OIDC login.

### 9. Mail — `cursor/go-port-mail-fa3b`

SMTP from the settings keys (`smtp_host`, port, encryption, username, password, sender). Render the existing Twig templates, including a copy under `storage/templates/emails/` when one exists, and inline CSS the way the current mailer does.

Wire forgot-password, reset-password, account-created, share-created, and download notification. Subjects come from the `email_subject_*` settings.

### 10. Reverse shares and self-registration — `cursor/go-port-invites-fa3b`

Reverse-share invite, accept-by-token, and accept-by-id for a logged-in user whose email matches. Guest users created by an invite can upload.

Self-registration: settings read, register, verify, resend code. Domain allow-list and the enable flag are honored.

### 11. Housekeeping — `cursor/go-port-housekeeping-fa3b`

Stats, database backup list/create/download/delete, and the email-template list/update API.

In-process schedules, matching `routes/console.php`: hourly expired-share warning; daily expiry warning, deletion warning, file cleanup, SQLite maintenance, log pruning, and database backup.

### 12. Cutover — `cursor/go-port-cutover-fa3b`

The Docker image runs the Go binary instead of php-fpm. Caddy and tusd stay. Caddy proxies the app to the Go process and keeps `/files/*` on tusd. The queue worker, cron, and PHP packages come out of the image.

Dev compose keeps the Vite dev server. `go test ./...` covers the API. This slice is the one that is exercised in a browser: setup, login, upload, the share link in a browser and with curl, expire, and an admin settings save.

## After this series

OIDC is a separate set of pull requests into the same integration branch: provider storage and admin CRUD, then Google, Microsoft, Authentik, and generic OIDC, then account linking. The empty provider list from slice 2 is replaced when that starts.
