# Manager password reset — 2026-11-01

This authorized account-management follow-up lets a manager help someone who has forgotten their password through the existing manager dashboard. It covers workers and other managers, including inactive accounts, while keeping the interface compact. The user approved implementation on 2026-11-01 with the usual documentation, tests and review gate. No real account password is reset during implementation; automated checks use synthetic accounts in the guarded MariaDB test schema.

## Agreed policy and scope

| Decision | Approved behavior |
|---|---|
| Who may act? | An authenticated **active manager**, with a current session version. Workers, guests, inactive managers and revoked/stale manager requests cannot use the endpoint. |
| Which accounts? | Another worker or manager, whether active or inactive. Permanently deleted accounts cannot be reset. |
| Own account? | Use the existing **הפרופיל שלי** password form, including current-password verification. Administrative self-reset is rejected on the server and has no account-card control. |
| What input? | A new password and matching confirmation. Neither the target's forgotten password nor the acting manager's password is required. |
| Password rules? | Existing rules: at least **12 characters**, at most **72 UTF-8 bytes**, and matching confirmation. Preserve literal password contents, including whitespace, and hash them securely once through Laravel. |
| Target sessions? | Revoke all stored sessions, clear the remember token and advance the existing session version together with the new hash. Even a late write of an old session remains invalid. |
| Acting manager? | Keep that manager signed in. A separate concurrent revocation of the actor, including a password reset by another manager, still applies. |
| Inactive target? | Leave the account inactive. A new password does not bypass suspension; reactivation remains a separate existing action. |
| Other data? | Preserve stable ID, username, role, profile/photo, active state, shifts, desk reservations, receipts and all history. Only password/authentication metadata and normal update timestamps change. |
| Next login? | Active targets sign in normally with the new password. They may change it later through their profile; no mandatory next-login password change is added. |
| Credential delivery? | The manager communicates the password privately. The application does not email it, display it after saving, log it or retain a plaintext copy. |
| Interface? | A closed native **שינוי סיסמה** control within each other account's existing card. No new page, dashboard section or navigation item. |

There is no migration, new database field, reset-token/email workflow, notification, password-generation feature, account search/filter change, scheduled task, external library or password-reset terminal command in this follow-up. Existing own-profile password behavior and manager account creation remain separate workflows. General editing of other users' personal profile fields remains outside scope.

The request also rejects NUL characters with ordinary Hebrew validation because the configured bcrypt hasher cannot accept those bytes. This is input compatibility with the existing hasher, rather than an additional letter/digit/symbol complexity requirement.

## Manager instructions

1. Sign in as a manager and open **רשימת משתמשים** on the manager dashboard.
2. Find the worker or other manager by the visible full name and username. Check the account's **פעיל** or **לא פעיל** status so there is no confusion about whether that person can currently sign in.
3. Expand **שינוי סיסמה** on that account's card. Enter **סיסמה חדשה** and **אימות סיסמה**, then choose **שמירת סיסמה**. Both fields must match and satisfy the password rules above. Opening or closing the control alone changes nothing.
4. Confirm the success message and give the new password to that person privately. The form does not reveal or repopulate the password after submission. You remain signed in; the target must sign in again.
5. For an inactive target, the account remains disabled. Use the separate **הפעלת חשבון מחדש** action only when you intend to restore access. Reactivation then uses the newly set password and does not restore canceled bookings.

The target browser is returned to login on its next application request; the server does not push a logout message to idle browsers. Session-version checks prevent an old session from regaining access even if an earlier request writes its session row after the reset.

Your own account card has no administrative reset control. Use **הפרופיל שלי** → its existing password form to change your own password with your current password. That existing self-service flow preserves your current browser session and revokes other stored sessions, whereas a manager reset revokes every target session.

## Request-to-code reading guide

1. **Route and access:** [routes/web.php](../routes/web.php) registers `PATCH /admin/users/{user}/password` as `admin.users.password.update` inside the existing authenticated, active-account, manager-only group. The native form sends a CSRF-protected POST with Laravel's PATCH method override. Route binding selects the account identity; a submitted body cannot change that target.
2. **Validation:** [ManagerPasswordResetRequest](../app/Http/Requests/ManagerPasswordResetRequest.php) authorizes an active manager resetting another account and reuses `UpdatePasswordRequest::rules()['password']` for the new password/confirmation. It adds the NUL-character compatibility guard and rejects attempts to change unrelated account fields. Its `failedValidation()` explicitly returns web errors in the `passwordReset` bag and flashes **only** the route-selected `password_form_id`; passwords, unrelated request input and a tampered presentation ID never enter old input. A submitted `_error_bag` cannot move errors into account creation. JSON validation retains the standard 422 response. The form ID restores presentation only; the route-bound account remains authoritative.
3. **Coordination and return page:** [Admin/UserController::updatePassword()](../app/Http/Controllers/Admin/UserController.php) receives validated input, passes the acting and target users plus the replacement password to the service, then redirects to the account list with a safe Hebrew success message. Both success and validation failure preserve the account list's current page through an internal, validated `page` query parameter. The request builds the dashboard return URL itself rather than accepting an arbitrary external destination.
4. **Transaction:** [AccountManagementService::resetPassword()](../app/Services/AccountManagementService.php) uses [ManagerTransaction::run()](../app/Services/ManagerTransaction.php). The shared schema-specific manager gate serializes administrative changes; the transaction rechecks the actor's current active role and session version before the target row is locked. The current target row is then checked again, so stale route-bound state cannot recreate a deleted account or overwrite unrelated concurrent account changes.
5. **Persistence and revocation:** the service saves a Laravel hash, clears `remember_token`, increments existing `session_version` and removes every `sessions` row owned by the target in the same transaction. The unchanged [User::casts()](../app/Models/User.php) accepts the freshly produced hash without hashing it again. Hashing the literal chosen input first means even a hash-shaped password is treated as the user's chosen text. On failure, the transaction rolls back the hash and authentication metadata together.
6. **Subsequent requests:** [EnsureAccountIsActive::handle()](../app/Http/Middleware/EnsureAccountIsActive.php) already compares the session's `auth_session_version` with the fresh account row. Old target requests cannot make a late stored session usable. [ProfileService::lockActiveUser()](../app/Services/ProfileService.php) and existing manager/booking/receipt services recheck that version under their write locks, rejecting stale requests that were already waiting when the reset occurred. A fresh login records the new version.
7. **Presentation:** [admin/users/index.blade.php](../resources/views/admin/users/index.blade.php) includes [password-reset.blade.php](../resources/views/admin/users/password-reset.blade.php) for each other account. The partial renders a native `<details>` with shared field/button components. [field.blade.php](../resources/views/components/field.blade.php) accepts the named `passwordReset` validation bag so reset errors cannot mark unrelated account-creation fields as invalid. Scoped rules in [public/css/app.css](../public/css/app.css) keep the form within its card at mobile widths. Existing [public/js/app.js](../public/js/app.js) enhances password visibility; the reset itself requires no JavaScript. Unrelated account cards remain closed after a validation failure, and password inputs remain blank.

Manager-versus-manager resets are safe under the same gate: if manager A resets B before B's waiting action acquires the gate, B's old login version is rejected inside the transaction. Target row locks also serialize the reset with own-profile updates, bookings, reactivation and deletion. If a target action commits before the reset, the reset preserves that committed business data; after the reset, the old target request cannot make a new authorized write. A reset does not cancel or alter bookings.

## Files created or modified

| File | Responsibility |
|---|---|
| `routes/web.php` | Named manager-only PATCH endpoint using existing web/CSRF middleware. |
| `app/Http/Requests/ManagerPasswordResetRequest.php` | New authorization, password validation, form-specific feedback and unrelated-field rejection. |
| `app/Http/Controllers/Admin/UserController.php` | New `updatePassword()` coordination and safe success redirect. |
| `app/Services/AccountManagementService.php` | New target-locked reset, literal-input hashing and atomic session-version revocation. |
| `resources/views/admin/users/index.blade.php` | Includes the compact reset control on other accounts. |
| `resources/views/admin/users/password-reset.blade.php` | New native inline reset form, target identity, inactive-state notice and account-specific validation feedback. |
| `resources/views/components/field.blade.php` | Optional named validation bag, preserving the default behavior for existing forms. |
| `public/css/app.css` | Scoped account-card reset styling using existing shared tokens and responsive form rules. |
| `tests/Feature/ManagerPasswordResetTest.php` | Focused HTTP, authorization, password/session, data-preservation, rollback and interface scenarios. |
| `tests/Feature/ManagerPasswordResetConcurrencyTest.php` | Independent-process MariaDB races against account and self-service operations. |
| `AGENTS.md` | Confirmed password-reset rules and narrow exception to the profile-editing restriction. |
| `ROADMAP.md` | Authorized follow-up, review gate and verification record without advancing stage approval. |
| `README.md` | Concise newcomer instructions and guide link. |
| `docs/DEVELOPMENT.md` | Owner acceptance steps and request-to-code reading guide. |
| `docs/EXTRA-FEATURES-REPORT.md` | Follow-up index and scope summary. |
| `docs/MANAGER-PASSWORD-RESET.md` | Complete policy, usage, implementation, verification and manual review. |

Existing `ManagerTransaction`, `User`, login, account-session middleware and worker business services supply the authority/session mechanisms; this feature reuses them without a new identity or authentication system. The five HTML prototypes remain read-only references. Updating an installation for this follow-up requires the ordinary application-update/deployment procedure only; no migration or new package is required.

## Manual acceptance

Use a disposable review installation and accounts for these checks. Do not reset an employee's real password merely to test this feature.

1. **Active worker:** open **שינוי סיסמה** on another worker's card, confirm the visible name/username and set a valid password twice. The actor remains signed in; the target's separately signed-in browser returns to login on its next request. The old password fails and the new one reaches the worker calendar without an extra password-change screen.
2. **Other manager:** repeat on another active manager. That target signs in to the manager dashboard with the new password; no worker permissions appear and the actor stays signed in.
3. **Inactive worker/manager:** reset an inactive account and verify the status remains **לא פעיל**. Login still fails until separate reactivation. After reactivation, the new password works; the previous password does not, and canceled bookings stay canceled.
4. **Own password:** verify your account card has no administrative reset form. The profile password form still requires the correct current password and retains its existing behavior.
5. **Validation:** submit a short password, mismatching confirmation or a password beyond 72 UTF-8 bytes. The proper account's form shows Hebrew errors; neither password is repopulated, and no unrelated account-creation field or other card receives those errors. Credentials and other records remain unchanged after rejection. Passwords with Hebrew characters may reach the byte limit before 72 characters.
6. **Preserved data:** compare the target's role, activation status, profile/photo, scheduled shifts, active desk reservations, receipts and cancellation history before/after reset. All should remain unchanged. An already inactive target does not become active.
7. **Compact accessible UI:** review at narrow mobile and desktop widths. The control is closed initially; open it with keyboard navigation, check field labels and readable target identity, save with JavaScript disabled, and confirm no page-wide horizontal overflow or nested form. Other account controls and pagination still work.
8. **Session revocation:** use independent browser profiles or private sessions, rather than two ordinary windows sharing one cookie. Refresh both target sessions after resetting and verify both need a new login. Automated race cases cover the late-session/write timing that is impractical to reproduce manually.

## Verification record

Verification on 2026-11-01 used synthetic accounts in the guarded dedicated MariaDB test database:

| Check | Actual result |
|---|---|
| `php artisan test --compact --stop-on-failure --stop-on-error` | **634 tests passed, 4671 assertions, 79.39 seconds; zero failures/skips, exit code 0.** Includes **61 new cases**: 52 password-reset feature cases and nine independent-process concurrency cases. |
| `vendor/bin/pint --test` | Passed globally. |
| `php artisan view:cache` | Passed. |
| Named route and middleware inspection | Passed: the new PATCH route has `auth`, `auth.session` and `role:manager` guards. |
| Composer configuration validation | Passed. |
| Chromium layout/behavior checks | **126 passed, zero failures**, across 320, 375, 700 and 1440 px, including JavaScript-disabled rendering and account-list page 2. |
| Temporary authenticated snapshot renderer | **One test / seven assertions passed in 0.634 seconds**, separate from the main suite above. |
| Local Markdown file/heading links and `git diff --check` | Passed. |

The new feature cases cover active/inactive workers and managers; correct role destinations; voluntary later profile changes; inactive login refusal until explicit reactivation; unchanged identity, role, activation, profile/photo, shifts, desk reservations, receipts and shared records; literal hash-shaped/whitespace passwords; character and UTF-8-byte boundaries; malformed/NUL input; unrelated-field rejection; rollback if session removal fails; target-only stored-session revocation; replay of a late old session; guest/worker/inactive/stale-manager denial; forbidden self-reset; CSRF; deleted targets; scoped, blank validation feedback; tampered form IDs/error-bag overrides; JSON errors; and safe account pagination/redirects. The nine independent-process MariaDB cases exercise reciprocal manager resets and both orderings of reset versus deactivation, deletion, another manager's waiting action and the target's self-service password change.

Verification caught and corrected two issues before the final passing run. An initial session test fixture changed Laravel's in-memory authenticated user instead of establishing real HTTP/database-session logins; it was corrected to exercise the actual cookie/session flow. Separately, a UI regression proved that normalizing a hidden form ID on the FormRequest clone does not change the original HTTP request that Laravel's default exception handler flashes. The final `failedValidation()` explicitly flashes only the route-selected form ID and fixes the named error bag, so a tampered ID cannot open the wrong account's form and a submitted `_error_bag` cannot move errors into account creation. Regression checks for those cases pass in the full result above.

An earlier full-suite attempt was interrupted when the local MariaDB process shut down normally; an older shift-concurrency fixture then encountered a missing socket during migration. The existing database directory was restarted without resetting its files or application data. The fresh full run recorded above completed successfully, with no unresolved automated test failures. This infrastructure interruption is separate from the corrected feature/fixture checks.

The browser checks used real authenticated route-rendered **static snapshots** with the actual application assets. They covered closed/open cards, private empty password fields, inactive-state text, password visibility controls, scoped validation feedback, keyboard access, JavaScript-disabled operation, internal page-2 actions, form boundaries and responsive overflow. The 320/1440 px open-card and 320 px invalid-form screenshots were also inspected visually. Evidence is outside Git at `/tmp/shift-manager-password-preview/browser-evidence.json`. These browser checks do not claim live browser submissions; HTTP actions, authentication, session revocation and database writes are exercised by the main MariaDB suite. No browser package was added to project dependencies.

Owner acceptance, Raspberry Pi verification and final office-pilot approval remain pending. This follow-up changes no stage approval and performs no real account reset, migration or application business-data operation during development.
