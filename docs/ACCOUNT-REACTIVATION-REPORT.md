# Account reactivation — 2026-10-22

## Authorized behavior

An active manager can now reactivate an inactive worker or manager from the account list in `/admin`. The `הפעלת חשבון מחדש` button submits a native form without asking for a username, password or additional confirmation fields. The account retains its ID, role, username, password hash, profile/photo, receipts and historical work records.

Canceled shifts and desk reservations remain canceled. Workers must explicitly select or book again using the current scheduling rules. Completed history is untouched. Permanently deleted accounts cannot be reactivated. A repeated request for an already active account is a harmless no-op and preserves its current login sessions.

Reactivated users sign in again with their existing credentials. Stored sessions are removed, and a new account session version prevents an old request from reviving access by writing a stale authenticated session afterward. The initial version is zero, so existing accounts and their sessions remain compatible when the migration is applied.

This is the explicitly authorized account-management follow-up. Stage 6 remains locked. No actual local account was reactivated or deactivated as part of implementing the option.

## Changed files and request path

| File | Purpose |
|---|---|
| `routes/web.php` | Manager-only named `admin.users.reactivate`: `PATCH /admin/users/{user}/reactivate`. |
| `app/Http/Requests/ReactivateUserRequest.php` | Active-manager authorization; accepts no editable account fields. |
| `app/Http/Controllers/Admin/UserController.php` | `reactivate()` coordinates the service and Hebrew status redirect. |
| `app/Services/AccountManagementService.php` | `reactivate()` locks and rechecks the target, activates it and advances its session version without changing credentials or work records. |
| `database/migrations/2026_10_22_000002_add_session_version_to_users.php` | Adds `users.session_version`, an unsigned integer defaulting to zero. |
| `app/Models/User.php` | Casts and hides the session version; excludes it from mass assignment. |
| `app/Http/Requests/LoginRequest.php` | `authenticate()` stamps the authenticated account version into the server session. |
| `app/Http/Middleware/EnsureAccountIsActive.php` | Rechecks account activity and session version, ending stale logins with a Hebrew sign-in message. |
| `resources/views/admin/users/index.blade.php` | Inactive-only button and explanation that canceled bookings remain canceled; permanent deletion remains available separately. |
| `public/css/app.css` | Scoped form spacing using the shared design. |
| `tests/Feature/AccountReactivationTest.php` | Both roles, preserved fields/history, login, sessions, stale-session replay, authorization, idempotence and native form coverage. |
| `tests/Feature/AccountReactivationConcurrencyTest.php` | Independent MariaDB connections race reactivation against permanent deletion and acting-manager deactivation. |
| `tests/Feature/CsrfProtectionTest.php` | Missing/incorrect/valid CSRF checks for reactivation. |
| `AGENTS.md`, `ROADMAP.md`, `README.md`, `docs/DEPLOYMENT.md` | Confirmed behavior, review instructions and migration details. |

Follow the form in `admin/users/index.blade.php` → `admin.users.reactivate` → `ReactivateUserRequest` → `Admin/UserController::reactivate()` → `AccountManagementService::reactivate()`. The existing `ManagerTransaction::run()` serializes manager actions and rechecks the acting manager under a lock. The service then locks a fresh target `User` row. This serializes reactivation against deletion: an activated target cannot be permanently deleted, and an already deleted target cannot be recreated by a stale request.

Only the active flag, session version, remember token and update timestamp change. Stored sessions are deleted in the same transaction. There are no shift/desk writes, so canceled records stay canceled and no date locks are needed for this operation. The controller redirects to the account list, rendered through the existing shared layout. There is no new JavaScript.

For the next login, `LoginRequest::authenticate()` records `auth_session_version` from the model that actually passed authentication. It deliberately does not refresh that model into a newer version after a concurrent reactivation. `EnsureAccountIsActive::handle()` compares the session's version with the current database value on every authenticated request. A missing legacy stamp means zero; malformed or stale versions are rejected. Session versioning leaves the password hash and the 30-day inactivity lifetime unchanged.

## Migration and verification

Update another installation with `php artisan migrate`, using its existing private environment or the deployment migration account described in `DEPLOYMENT.md`. The migration adds a column; it does not activate accounts, reset passwords, restore bookings or purge data. No new library, storage link or background service is required.

| Check | Actual result |
|---|---|
| Full MariaDB suite: `php artisan test --compact --stop-on-failure` | **376 passed, 2202 assertions, 64.57 seconds; no failures or skips.** |
| Focused login/reactivation/CSRF suite | **45 passed, 362 assertions, 11.02 seconds.** |
| New reactivation scenarios | 20, including both roles, real database-session replay, native forms, CSRF and independent-process MariaDB races. |
| `vendor/bin/pint --test` | Passed across the application. |
| `php artisan view:cache` | All Blade templates compiled successfully. |
| `php artisan route:list --path=admin/users` | Create, deactivate, reactivate and permanent-delete routes registered with their intended methods. |
| `git diff --check` | Passed. |
| Reference checksums | All five HTML references and the original logo unchanged. |
| `php artisan migrate` | Session-version migration applied successfully to the persistent local database. |
| Local HTTP smoke check | `/login` returned HTTP 200 after migration. |

The first full attempt stopped on a test-fixture comparison: Eloquent's initial date representation contained a midnight time, while MariaDB's persisted `DATE` did not. The fixture now reloads the persisted account before comparing unchanged fields. No runtime date/profile change was needed. The focused and final full runs above then passed. The session replay test verifies that a recreated old session is rejected while a fresh login with the original password succeeds. No known failing automated check remains.

## Manual review

1. With a disposable worker, save a shift, desk reservation and receipt state. Deactivate the account as manager and confirm login is refused.
2. In `/admin`, click `הפעלת חשבון מחדש` on that inactive account. There are no credential fields. Confirm the account becomes active and its owner can sign in with the original username/password.
3. Check the retained profile/photo and receipt state. Completed work history remains; canceled shifts and desk reservations remain canceled. A fresh booking creates a new record under the normal date/availability rules.
4. Repeat with a disposable inactive manager. Its administration role remains unchanged. Check keyboard/mobile RTL and the native form with JavaScript disabled. Previously revoked login sessions must ask the owner to sign in again.

The MariaDB tests use only the guarded `_test` schema. Browser acceptance and Raspberry Pi deployment remain for owner review; automated tests do not substitute for those manual checks.
