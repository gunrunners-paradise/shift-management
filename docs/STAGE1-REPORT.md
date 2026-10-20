# Stage 1 delivery for review — 2026-10-18

Later progression note (2026-10-20): the user explicitly authorized Stage 2. The following is the historical Stage 1 report; current scheduling behavior, persistent local runtime and review steps are described in `README.md` and `docs/STAGE2-REPORT.md`.

Stage 1 is implemented and tested. User acceptance is pending. At initial delivery, `AGENTS.md`, `ROADMAP.md`, all five HTML references and the original logo were unchanged. A subsequent user-approved wording correction changed manager titles to `מנהל` / `מנהלת` in the application, tests, `AGENTS.md` and three HTML references. `ROADMAP.md` contained no old-title references and remains unchanged. No later stage has been unlocked.

Runtime recovery on 2026-10-20: after the temporary verification database disappeared, the user authorized a fresh persistent local database. Data now lives under the ignored `.local/mariadb/data/`, with private application/test credentials and the original application keys preserved. `scripts/local-database.sh` provides start/status/graceful-stop commands. Both existing migrations were applied; all 55 tests passed (322 assertions), and retained migration records were verified after a database restart. A live HTTP check confirmed the login form and assets return 200, while a tokenless login returns 419. The local database was started from a desktop terminal after the automated runner stopped its background child; the operator uses the same documented start command after reboot. The current startup instructions are in `README.md` and `docs/DEPLOYMENT.md`; the original verification notes below describe the initial delivery. Stage approval is still pending.

## Delivered scope

- Laravel 13.37.0 / PHP 8.3, conventional controllers, Form Requests, Eloquent and Blade; native MariaDB/InnoDB configuration, Hebrew RTL and `Asia/Jerusalem`.
- Username/password session login, logout, session/token rotation, account and IP rate limits, CSRF, active-account checks and role-exclusive routes.
- Secure `app:create-manager` interactive bootstrap with hidden confirmed password, chosen username, configured sign-in URL and repeat-run protection. No default account or credentials.
- Manager account creation for workers and managers, paginated listing, confirmed deactivation, stored-session deletion and protection of the final active manager. Stable user records are retained.
- Schema-specific MariaDB advisory lock plus transaction for account mutations, including first-manager setup when no row exists. Authorization is rechecked after acquiring the lock.
- Separate guest and authenticated layouts, shared components and actual organization logo. Native accessible dropdowns, progressive password visibility, Hebrew errors and responsive CSS.
- Truthful worker landing page stating that the calendar arrives in Stage 2.
- Setup, test, Apache, private environment, session security, log-retention and off-device backup/restore instructions.

Not implemented: shifts or blocked dates, profiles or uploads, birthdays or coworkers, events or holidays, receipts, desks or reservations, CSV, notifications or attendance tracking. Only the two Stage 1 application tables (`users`, `sessions`) were added; Laravel also maintains its migration ledger.

## File inventory

The workspace originally contained only the instructions, roadmap, five references and logo. All application files below are new; none of the original references were rewritten.

| Area | Files |
| --- | --- |
| Framework entry/configuration | `artisan`, `public/index.php`, `public/.htaccess`, `public/robots.txt`, `bootstrap/app.php`, `bootstrap/providers.php`, `app/Providers/AppServiceProvider.php`, `app/Http/Controllers/Controller.php`, conventional `config/*.php` |
| Dependencies and environment | `composer.json`, `composer.lock`, `.env.example`, `.env.testing.example`, `.gitignore`, `.editorconfig`, `.gitattributes`, `phpunit.xml` |
| Routes/controllers | `routes/web.php`, `routes/console.php`, `app/Http/Controllers/HomeController.php`, `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `app/Http/Controllers/Admin/UserController.php` |
| Validation/security | `app/Http/Requests/LoginRequest.php`, `StoreUserRequest.php`, `DeactivateUserRequest.php`; `app/Http/Middleware/EnsureAccountIsActive.php`, `RequireRole.php`, `SecureResponseHeaders.php`; `app/Rules/PasswordByteLimit.php`; `lang/he/validation.php` |
| Accounts/bootstrap | `app/Models/User.php`, `app/Services/AccountManagementService.php`, `app/Console/Commands/CreateFirstManager.php` |
| Migrations | `database/migrations/0001_01_01_000000_create_users_table.php`, `0001_01_01_000001_create_sessions_table.php` |
| Views | `resources/views/layouts/{guest,app}.blade.php`; shared `components/{header,dropdown,panel,button,field,messages}.blade.php`; `auth/login.blade.php`, `admin/users/index.blade.php`, `worker/home.blade.php`; shared Hebrew `errors/` views for 403/404/419/429/500/503 |
| Assets | `public/css/app.css`, `public/js/app.js`, `public/images/enter_digital_studio.svg` (unchanged copy), `public/images/default-avatar.svg` |
| Tests | `tests/TestCase.php`, `tests/Feature/{AuthenticationTest,AccountManagementTest,CsrfProtectionTest,FirstManagerSetupTest,ManagerDeactivationConcurrencyTest}.php`, `database/factories/UserFactory.php` |
| Documentation | `README.md`, `docs/DEPLOYMENT.md`, this report |

Laravel's writable directories also contain standard ignore files. Downloaded `vendor/`, generated caches, logs and private `.env` / `.env.testing` are excluded from source control. Both private environment files have mode `0600`. The supplied workspace has no initialized Git repository, so comparison used SHA-256 snapshots of the original files instead of `git diff`.

## Request reading guide

**Login:** `GET /login` → `AuthenticatedSessionController::create()` → `resources/views/auth/login.blade.php` through `layouts/guest.blade.php`. The form posts to the named `login.store` route. `LoginRequest::authenticate()` enforces rate limits and calls Laravel's session authentication with `is_active=true`; Eloquent reads `users` and verifies the password hash. `store()` rotates the session and redirects managers to `/admin`, workers to `/worker`. `public/js/app.js` only adds password visibility and dropdown conveniences.

**Create an account:** `POST /admin/users` → auth/active-account/manager middleware → `StoreUserRequest` → `Admin/UserController::store()` → `AccountManagementService::create()` → MariaDB transaction and `User::create()`. The model's hashed cast hashes the password. The redirect invokes `UserController::index()`, which reads paginated records and renders `resources/views/admin/users/index.blade.php` through the shared authenticated layout/header. No JavaScript is required to submit the form.

**Deactivate:** `PATCH /admin/users/{user}/deactivate` → `DeactivateUserRequest` verifies confirmation → `UserController::deactivate()` → `AccountManagementService::deactivate()`. Under the account lock, the service rereads the actor, protects the last active manager, marks the target inactive and deletes its stored sessions in one transaction. Self-deactivation also logs out the current request. `EnsureAccountIsActive::handle()` checks the DB on subsequent web requests, covering a session rewritten by an overlapping request. There are no shift/desk records to cancel in Stage 1.

## Commands and actual verification

Executed migrations against a separate local MariaDB 10.11.14 database:

```bash
php artisan migrate --force
```

Both migrations passed. The existing system MariaDB service was not changed. Integration tests used a second dedicated MariaDB schema with independent credentials.

| Check | Actual result |
| --- | --- |
| `php artisan test --compact` | **55 passed, 322 assertions, 0 failures/skips**, 7.58 seconds |
| MariaDB process races | Passed: two self-deactivations, mutual deactivation with stale actors, simultaneous first-manager bootstrap |
| Enabled CSRF middleware tests | Passed: missing/incorrect token rejected; valid token accepted; management and logout protected |
| Bootstrap tests | Passed: chosen credentials sign in, password absent from output, confirmation required, repeat run preserves hashes/accounts, inactive manager not replaced |
| `vendor/bin/pint --test` | Passed |
| `composer validate --strict` | Passed |
| `composer check-platform-reqs` | Passed on PHP 8.3.6 |
| `php artisan view:cache` | Passed |
| `php artisan route:list --except-vendor` | Only the eight intended application routes |
| Real HTTP server | Login returned 200; CSS, JS and both images returned 200; tokenless login returned Hebrew 419; guests on `/admin` and `/worker` redirected to login; no-store/security headers present |
| Browser layout inspection | Firefox screenshots inspected: login at 390px, manager dashboard at 1440px, worker landing at 320px. Header order and mobile fit verified; password row alignment corrected |
| Reference integrity | SHA-256 confirmed instructions, roadmap, five references and original SVG unchanged; public logo matches original |

Initial sandbox runs could not download dependencies or connect to database/browser sockets. Approved elevated tool execution resolved those environment limits. An initial MariaDB run found two failures from an authorization exception mismatch, which was corrected before the successful full suite. The password byte-limit regression was added afterward; the final result above includes it.

Browser screenshots used temporary unsaved preview users, with no database mutation or production preview route. Business behavior was checked by MariaDB feature tests and real HTTP checks. Keyboard/menu/visibility interactions should still be exercised by the owner during acceptance. Apache on Raspberry Pi, organizational HTTPS and an actual off-device restore have not been validated.

## Operator acceptance steps

The local schema has no manager account yet. In the workspace terminal, while the temporary database is available:

```bash
php artisan app:create-manager
php artisan serve --host=127.0.0.1 --port=8000
```

Choose your own username and hidden password. The command prints the username and configured URL, currently **http://127.0.0.1:8000/login**. Do not send the password through chat. No default credential is required.

1. Sign in, create a worker and another manager, and confirm duplicate usernames and invalid fields produce Hebrew feedback.
2. In a private browser window, sign in as the worker and confirm it reaches the Stage 2 notice. Visit `/admin` as the worker and `/worker` as a manager; both cross-role requests must return 403.
3. Keep the worker logged in, deactivate it from the manager window, and refresh its page. Confirm immediate access refusal and rejected subsequent login.
4. Confirm a second manager can be deactivated while the last active manager remains protected. Records stay listed as inactive.
5. Test logout, browser Back, keyboard menus, Escape/outside dismissal, password visibility, and the 320px/mobile header.
6. Repeat `php artisan app:create-manager`. Confirm it prints the existing username/URL without prompting for or changing a password.

The `/tmp` database is disposable. Use `docs/DEPLOYMENT.md` to connect to persistent MariaDB before retaining real accounts. Do not overwrite an existing `.env` or application key while switching connections.

## Remaining review items

- The operator must run bootstrap, choose credentials and complete the manual acceptance steps. No operator password was chosen by the agent.
- Persistent database provisioning needs the operator's database administrator access; the supplied system service refused passwordless access.
- Real Raspberry Pi deployment, HTTPS and a successful backup/restore rehearsal remain unverified and are documented for the relevant deployment review.
- Stage 1 has not been marked accepted in `ROADMAP.md`. Stop here for the owner's explicit review and approval; no Stage 2 work has begun.
