# Permanent account deletion — 2026-10-22

## Authorized scope and result

The user explicitly approved a permanent-delete option for inactive accounts, deletion of their history, and immediate removal of the currently inactive local accounts. Managers now have a native confirmation form on each inactive account. It requires only acknowledgement that deletion is irreversible; no username or password entry is required. Active accounts must first be deactivated; ordinary deactivation still preserves history and protects the last active manager.

Permanent deletion removes the account's completed, canceled and future shifts, desk reservations, monthly receipt records, stored sessions and profile photo. Shared blocks, events, desks and other workers' history remain; references naming the deleted account as an actor become null. A removed username can be reused by a new account without inheriting its history.

The approved local cleanup **deleted one inactive account**. That account had no linked shifts, reservations, receipts or photo. Verification found **zero remaining inactive accounts**, no remaining owned history or sessions for the deleted account, and the same **three active accounts, including one active manager**. No live active account was deactivated or deleted. Temporary inventory and cleanup helpers were kept outside the repository and reused the tested deletion service.

Stage 6 remains locked. No automatic purge, account reactivation, report feature or future-stage system was added. The five HTML references, logo and confirmed calendar layout order remain unchanged.

## Changed files and reading guide

| File | Purpose |
|---|---|
| `routes/web.php` | Named manager-only `admin.users.destroy` route: `DELETE /admin/users/{user}`. |
| `app/Http/Requests/DeleteUserRequest.php` | Manager authorization and acknowledgement validation; scopes failed input to the correct form. |
| `app/Http/Controllers/Admin/UserController.php` | `destroy()` coordinates the request, service and redirect. |
| `app/Services/AccountDeletionService.php` | `delete()` rechecks authority and inactivity, removes personal history/sessions, clears actor references and deletes the user transactionally; avatar removal follows commit. |
| `database/migrations/2026_10_22_000001_allow_deleted_account_actors.php` | Makes the three previously required manager-actor columns nullable; retains restrictive foreign keys. |
| `resources/views/admin/users/index.blade.php` | Inactive-only native details/confirmation form and irreversible-deletion explanation. |
| `public/css/app.css` | Scoped confirmation layout using the shared colors and spacing. |
| `tests/Feature/PermanentAccountDeletionTest.php` | Cleanup, authorization, confirmation, shared state, avatars, rollback, username reuse and late sessions. |
| `tests/Feature/PermanentAccountDeletionConcurrencyTest.php` | Real MariaDB races with stale worker writes, acting-manager deactivation and duplicate deletion. |
| `tests/Feature/CsrfProtectionTest.php` | Missing/incorrect/valid CSRF token coverage for permanent deletion. |
| `AGENTS.md`, `ROADMAP.md`, `README.md`, `docs/DEPLOYMENT.md` | Confirmed policy, review steps, request flow and migration/recovery details. |

Follow `admin.users.destroy` → `DeleteUserRequest` → `Admin/UserController::destroy()` → `AccountDeletionService::delete()`. `ManagerTransaction::run()` serializes manager writes and rechecks the acting manager under a database lock. The service locks the inactive target and its owned dates in chronological order, deletes owned `Shift`, `DeskReservation` and `MonthlyReceipt` rows and sessions, clears six actor references, then deletes `User`. Permanent date-lock keys remain for other bookings. Restrictive foreign keys remain enabled throughout. `AvatarStorage::delete()` runs only after the outermost commit, so a rollback cannot leave a restored account without its photo.

The redirect returns to `index()` and `admin/users/index.blade.php`. The confirmation uses native `<details>` and a CSRF-protected form; no feature JavaScript was needed.

## Migration and initial delivery verification

Applied locally with `php artisan migrate`. Another installation must run that command using its existing private environment; deployment credentials are described in `DEPLOYMENT.md`. The migration itself deletes no account or history. Its rollback refuses to recreate required actor identities once null references exist; it will not invent a substitute manager.

| Check | Actual result |
|---|---|
| `php artisan test --compact --stop-on-failure` | **358 passed, 2046 assertions, 59.50 seconds; no failures or skips.** |
| New deletion scenarios | 26 additions, including independent-process MariaDB races and real commit/rollback avatar tests. |
| `vendor/bin/pint --test` | Passed across the application. |
| `php artisan view:cache` | All Blade templates compiled successfully. |
| `php artisan route:list --path=admin/users` | Create, deactivate and permanent-delete routes registered with their intended methods. |
| `git diff --check` | Passed. |
| Reference checksums | All five HTML references and the original logo unchanged. |
| Local application migration | Applied successfully. |
| Requested live cleanup | One inactive account deleted; active identities and manager count preserved; owned rows and sessions absent. |
| Local HTTP check | `/login` returned HTTP 200 after migration and deletion. |

The first full run had one failure in test teardown: the intentional migration rollback guard rejected null actor identities left by the deleted-manager fixture. The business assertions passed. Test-only cleanup now removes those disposable null-actor fixtures after assertions and before rollback, preserving the production guard. The final full run above passed. An unsupported terminal-color option was also rejected before tests ran; the successful command is shown above.

## Manual review

Use a disposable worker rather than an account whose history is needed:

1. Create it as manager, then sign in as that worker and save a shift, desk reservation and receipt state. Optionally upload a profile photo.
2. As manager, deactivate it. Confirm access is revoked and the existing deactivation/cancellation behavior remains.
3. Expand `מחיקה` on its inactive account. Check that the warning is clear and that acknowledging deletion and submitting removes the account without typing a username or password. Refresh the list and receipt report to confirm it is gone.
4. Check keyboard access, mobile RTL and submitting with JavaScript disabled. Confirm other accounts, shared desk identities, blocks and events remain available.

The automated suite covers the destructive/history and concurrency cases on its separate guarded test database. Browser acceptance of the new confirmation form and Raspberry Pi deployment remain for owner review; no new browser screenshots or target-device claims are made here. No known failing automated check remains.

## Confirmation simplification — 2026-10-22

The typed username confirmation was an unnecessary addition. Following the user's correction, deletion now needs only the acknowledgement checkbox and delete button, without entering any username or account password. `DeleteUserRequest` validates only the acknowledgement; the Blade field and its unused CSS were removed. The existing route, controller, deletion service, ownership cleanup, manager authorization and inactive-account requirement remain in use. No migration or application-data change was needed for this correction.

Updated deletion and CSRF tests cover successful requests containing only the acknowledgement, missing/declined/malformed acknowledgement, validation feedback and deletion forms without typed credentials. The focused MariaDB command `php artisan test --compact --filter='PermanentAccountDeletion|CsrfProtection'` passed **34 tests and 182 assertions in 14.41 seconds**, including the existing deletion race tests. Scoped Pint, Blade compilation and `git diff --check` passed. The earlier 358-test full run above records the initial delivery; it was not repeated for this form/validation correction.

To review, expand `מחיקה` for a disposable inactive account, acknowledge the deletion and click the delete button. There is no credential field to fill in.
