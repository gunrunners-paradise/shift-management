# Account signup and manager approval — 2026-11-03

This guide records the user-approved signup feature, how applicants and managers use it, its implementation and verification. The user authorized implementation on 2026-11-03 after confirming the policies below. It is a follow-up to the delivered stages, not permission to mark Stage 7 or office deployment accepted. Real registration remains closed during development; synthetic tests do not authorize real account creation.

## Contents

- [Confirmed specification](#confirmed-specification)
- [Hebrew interface wording](#hebrew-interface-wording)
- [Install or update](#install-or-update)
- [Applicant instructions](#applicant-instructions)
- [Manager instructions](#manager-instructions)
- [Request-to-code reading guide](#request-to-code-reading-guide)
- [Changed files](#changed-files)
- [Verification record](#verification-record)
- [Manual acceptance](#manual-acceptance)
- [Limits and deferred work](#limits-and-deferred-work)

## Confirmed specification

| Topic | Agreed behavior |
|---|---|
| Separate pages | `/signup/worker` requests a worker account; `/signup/manager` requests a manager account. They use the same form/layout, adapted from the login styling without its SVG logo. |
| No signup hyperlinks | No page, menu, dashboard or confirmation contains a hyperlink to either signup page. Managers distribute the relevant address directly. This guide presents paths as code rather than clickable URLs. |
| Independent settings | Persistent worker and manager switches are both **disabled by default**. An active manager can enable/disable either without changing the other. |
| Closed registration | Both viewing and submitting show **ההרשמה סגורה כעת** with HTTP 403. This includes submitting a form opened before closure. No new pending row is accepted after closure commits. |
| Fields | First name, last name, username, gender, password and confirmation are required. Birthday is optional; when supplied, it must pass existing date validation and cannot be in the future. |
| Passwords | Clearly show **הסיסמה חייבת להכיל לפחות 12 תווים.** Enforce 12 characters minimum, 72 UTF-8 bytes maximum and matching confirmation. Preserve literal whitespace, hash once immediately and never retain/display plaintext after submission. |
| Username identity | Apply existing trim/lowercase normalization and account validation. A username cannot duplicate any pending request across either role or any retained active/inactive account. Matching full names are allowed when usernames differ. |
| Pending state | Store pending requests separately from `users`. No login, worker privileges, receipts, roster/birthday visibility or CSV row exists until approval. |
| Waiting page | Successful submission redirects to a separate page with the approved waiting/contact-management message. It does not sign the applicant in or imply approval. |
| Fixed role | The page determines the role on the server. Neither applicants nor approving managers can change it. Delete an incorrect request and submit through the correct page. |
| Manager list | One combined **בקשות הצטרפות** section shows both roles, oldest first, ten per page. Its page parameter is independent of existing dashboard lists. |
| Controls above list | Both registration switches and **אישור כל הבקשות** / **מחיקת כל הבקשות** appear above the pending list. Individual approval/deletion is also available. |
| Bulk scope | All pending requests across **all** pages are included in the confirmed cohort, not just the displayed page. Later arrivals wait for another action. |
| Confirmation | One native server-rendered confirmation shows worker and manager counts and explains full manager permissions, including account deletion/password resets. There is no extra acknowledgement checkbox. |
| Approval | Create active accounts with the requested role and already stored hash. Users sign in with their chosen username/password and are not forced to change the password. Remove approved pending rows atomically. |
| Conflicts | Approve valid requests and leave conflicting usernames pending with manager-visible explanations. Never overwrite existing accounts or another pending request. Unexpected failures roll back the batch. |
| Deletion | Delete pending requests only, with a count and irreversible-deletion explanation. Never delete an existing account because its pending request was removed. |
| Retention | Requests remain until approved/deleted. Closing signup preserves them. No expiry or scheduled purge is installed. A deleted username can be submitted again while its page is open. |
| Communication | Managers communicate approval directly. No email field, notification service or public request-status lookup is included. |

The final role policy supersedes the earlier proposal that approval would have a role picker and bulk approval would create workers only. Both worker and manager requests now participate in bulk approval, retaining their page-assigned roles. A manager request is not evidence of that person's authority; the existing manager reviews identity before approving it.

## Hebrew interface wording

The visible interface is Hebrew. Names, comments and technical documentation remain English.

| Purpose | Wording |
|---|---|
| Common form title | **בקשת הצטרפות למערכת** |
| Role in form explanation | **בקשה לחשבון עובד/ת** or **בקשה לחשבון מנהל/ת** |
| Fields | **שם פרטי**, **שם משפחה**, **שם משתמש**, **מגדר**, **תאריך לידה (לא חובה)**, **סיסמה**, **אימות סיסמה** |
| Gender choices | **אישה**, **גבר**, **אחר / לא צוין** |
| Password help | **הסיסמה חייבת להכיל לפחות 12 תווים.** |
| Submit | **שליחת בקשה** |
| Closed page | **ההרשמה סגורה כעת** |
| Submission confirmation | **הבקשה נשלחה וממתינה לאישור. צרו קשר עם המנהל/ת לאישור חשבון המשתמש שלכם.** |
| Manager section | **בקשות הצטרפות** |
| Fixed request roles | **עובד/ת**, **מנהל/ת** |
| Registration controls | **פתיחת ההרשמה**, **סגירת ההרשמה** |
| Registration status | **ההרשמה פתוחה**, **ההרשמה סגורה** |
| Approve one / all | **אישור ויצירת חשבון**, **אישור כל הבקשות** |
| Delete one / all | **מחיקת בקשה**, **מחיקת כל הבקשות** |
| Empty list | **אין בקשות הממתינות לאישור.** |

Confirmation includes the exact worker and manager counts from the reviewed cohort. Manager approval explicitly grants the same permissions as existing managers, including account deletion and password resets. The browser's native forms must remain usable without JavaScript; confirmation is a real server page, not a JavaScript-only dialog.

## Install or update

The feature adds `2026_11_03_000000_create_signup_tables.php`. It creates `signup_settings` with one disabled setting per role and `pending_signup_requests` for validated applicant details and password hashes. Existing accounts, shifts, desk bookings, receipts, events and blocks are preserved. The migration creates no real user and opens neither page.

For the prepared development workspace, from the repository root:

```bash
bash scripts/local-database.sh start
php artisan config:clear
php artisan migrate
```

For an existing Pi installation using the documented private migration environment, back up first, preserve `.env`, `.env.migrate`, the original `APP_KEY` and uploads, then run from its project root:

```bash
php artisan down --retry=60
php artisan config:clear
php artisan migrate --env=migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan up
```

These are the existing [deployment update commands](DEPLOYMENT.md#raspberry-pi-and-apache-configuration); do not recreate the database or regenerate the key. If that installation uses a single schema-scoped local database account instead of `.env.migrate`, use its normal `php artisan migrate` procedure. Follow [the Pi guide](PI-INSTALLATION.md) for its service/path settings. No new Composer package, Node build, queue, scheduled task or storage link is needed.

Verify both settings are closed after the first migration. Applying later unrelated migrations or restarting does not reset the manager's saved choices. The bootstrap command `php artisan app:create-manager` remains the way to create the first manager; manager signup cannot approve itself.

Backups must retain both new tables as well as all existing tables and the original key. Pending rows contain personal details and password hashes; keep them in the same confidential MariaDB backup and restore process. Deleting/approving a request does not rewrite old backup archives. Signup settings are restored with the database, so check whether either page is enabled before exposing a restored installation. Existing backup schema/table-count checks should include `signup_settings` and `pending_signup_requests`.

## Applicant instructions

1. Obtain the correct address directly from management and open it in a guest browser. The site provides no signup links. Use the installation's base address plus `/signup/worker` or `/signup/manager` as instructed; the pages are separate and have no cross-link.
2. If **ההרשמה סגורה כעת** appears, contact management. A saved or previously opened form cannot bypass closure.
3. Enter first name, last name, username and gender. Birthday can be left blank and filled in later through your own profile. Usernames follow the existing login rules: 3–60 lowercase English letters/digits, dots, underscores or hyphens, beginning with a letter or digit. Uppercase/outer whitespace is normalized.
4. Enter the same password twice. It needs at least **12 characters** and no more than **72 UTF-8 bytes**; Hebrew characters use more than one byte. No current password is required. The role is determined by the page and cannot be selected in the form.
5. Choose **שליחת בקשה**. Correct validation errors and resubmit if needed. Password fields remain blank after errors and must be entered again.
6. Successful submission opens the waiting page: **הבקשה נשלחה וממתינה לאישור. צרו קשר עם המנהל/ת לאישור חשבון המשתמש שלכם.** Contact management. You cannot sign in while pending.
7. After the manager tells you approval is complete, use the normal login page with your chosen username/password. Workers reach the calendar; managers reach administration. Password changes remain optional through your own profile.

The application limits signup POST attempts to **20 per minute per IP**, shared across both role pages and including invalid attempts. This allows multiple people on the office connection to submit without a one-person-per-IP rule. After HTTP 429, wait before trying again; registration controls remain available to managers independently of that limiter. No hourly or daily signup quota is installed.

## Manager instructions

### Open or close registration

Sign in as an active manager and open **בקשות הצטרפות** in the existing dashboard. Worker and manager registration have separate status/control rows. Use **פתיחת ההרשמה** for the desired role and distribute that page's address directly. The dashboard intentionally contains no signup hyperlink. Keep the other role closed unless needed.

Use **סגירת ההרשמה** to stop new submissions. This blocks a person submitting an older form too. Pending requests remain available for decisions, and existing approved users retain their accounts. A switch changes no user role, password or active state. The setting persists across reloads/restarts.

### Review and approve

The combined list shows name, username, fixed role and submission date, with ten requests per page, oldest first. Expand the personal details when needed. Full names may match; use the username and direct knowledge of the person to distinguish requests. A request marked **מנהל/ת** receives full administration authority if approved. To correct the role, delete the request and ask the person to resubmit through the other page.

Use **אישור ויצירת חשבון** on an individual request, review its confirmation, then submit the decision. For a batch, choose **אישור כל הבקשות** **above the list**, read its separate worker/manager counts and manager-permissions explanation, then confirm once. There is no extra acknowledgement checkbox. Both roles are included across all list pages.

Approval creates active accounts with the displayed roles and the applicants' original password hashes, and removes their pending requests. Tell each approved person directly; the application does not send email or reveal passwords. Closing signup is independent of approving requests already received.

If a trusted manager/CLI workflow created the username after submission, approval leaves that request pending with an explanation and preserves the existing account. Other valid requests are still approved. Resolve a conflict by checking the existing account and deleting the unnecessary request; pending requests are not editable username/role forms.

### Delete requests and handle stale confirmations

Use **מחיקת בקשה** for one request or **מחיקת כל הבקשות** above the list for the entire reviewed cohort. Read the confirmation counts and irreversible-deletion explanation before confirming. Deletion removes pending details/hashes only; existing accounts and history are unaffected. Applicants can submit again using a deleted username if registration is open and the username is otherwise available. Deletion is not a ban.

Each confirmation is bound to your manager ID and current login version, expires after **30 minutes**, and freezes the requests included when reviewed. New requests arriving afterward wait for a later decision. Requests another manager already handled are ignored safely rather than recreated. If a confirmation expires or your session is revoked, return to the dashboard and review again using current authority. Do not expect browser Back or repeated submission to restore a deleted/approved request.

## Request-to-code reading guide

The implementation uses ordinary Laravel web routes, CSRF-protected forms, Form Requests, Eloquent and the existing `ManagerTransaction` gate. No signup account state lives in browser localStorage.

### Submission and registration closure

`GET /signup/{signupRole}` (`signup.create`) reaches `Auth/SignupController::create()` through guest-only and `EnsureSignupIsOpen::handle()` middleware. Only `worker` and `manager` paths are accepted. The controller renders `auth/signup.blade.php` using the minimal guest layout; it omits the login SVG. `POST /signup/{signupRole}` (`signup.store`) calls `SignupController::store()` through `SignupRequest`, which normalizes username and reuses existing account-field/password validation while rejecting client role/activation fields.

`SignupService::submit()` hashes the literal password once before acquiring the schema-specific account gate, keeping that expensive operation outside the shared lock. Under the gate, it locks/rechecks the role setting and username availability across `users` and pending records, then inserts `PendingSignupRequest`. The shared gate serializes submissions with registration closure, manager decisions, direct account creation and CLI imports. A form opened while enabled cannot submit successfully after closure wins that transaction order.

The successful redirect goes to `GET /signup/submitted` (`signup.submitted`) → `SignupController::submitted()` → `auth/signup-submitted.blade.php`. A session flag records only that submission succeeded, never the submitted personal fields or credentials. A direct visit without that flag returns 404. The `signup` limiter in `AppServiceProvider` shares 20 POST attempts per minute per IP across both forms. The closed-page middleware runs before validation/limiting and avoids hashing for already-closed pages; the service repeats the setting check after any wait so a racing closure still prevents storage. `auth/signup-closed.blade.php` provides friendly HTTP 403 feedback.

### Manager decisions and transaction boundaries

Authenticated manager routes reach `Admin/SignupRequestController`. Dedicated manager Form Requests validate setting/decision input; authorization is rechecked inside the shared gate using the actor's current active manager role and `session_version`. `Admin/UserController::index()` reads `SignupSetting` and paginates `PendingSignupRequest` independently of accounts/events/blocks/desks, then `admin/users/index.blade.php` includes `admin/signups/panel.blade.php`.

| Route | Request → controller → service |
|---|---|
| `PATCH /admin/signup-settings/{signupRole}` (`admin.signups.settings.update`) | `UpdateSignupSettingRequest` → `SignupRequestController::updateSetting()` → `SignupService::setEnabled()` |
| `POST /admin/signup-requests/review` (`admin.signups.review`) | `ReviewSignupRequestsRequest` → `SignupRequestController::review()` → `SignupService::review()` → `admin/signups/confirm.blade.php` |
| `POST /admin/signup-requests/process` (`admin.signups.process`) | `ProcessSignupRequestsRequest` → `SignupRequestController::process()` → `SignupService::process()` → dashboard redirect |

`SignupService::review()` builds a server-side confirmation under the shared account gate. Its encrypted token contains the action, fixed single/all cohort boundary, exact role counts, actor ID, session version and expiry; it contains no password hash or applicant personal details. Using a maximum pending ID freezes the cohort without copying thousands of IDs into a form or adding a confirmation table. Counts reflect the reviewed snapshot. The native confirmation page warns about manager permissions when applicable; mutation occurs only on its subsequent confirmed POST.

Processing verifies the token/actor/version/expiry and rechecks authority under the gate. For approval, the service locks the selected pending rows in stable order, rechecks usernames, creates each valid active `User` with its stored hash and fixed role, and removes that pending row in the same transaction. The `User` hashed cast accepts the generated hash unchanged; plaintext is not recovered. A conflict writes a manager-only `review_error` and leaves that request pending. Unexpected failures roll back all writes in the operation. Deletion removes selected pending rows only. Setting updates use the same gate and setting row lock.

Blade renders the section, forms and confirmation, with scoped `public/css/signup.css` for layout. Existing `public/js/app.js` enhances password reveal, focus and shared menus only; every primary signup/decision/settings flow works without JavaScript.

## Changed files

The implementation changes **35 files**: 25 application/migration/style/view files, two test files and eight Markdown files. The five HTML references remain untouched. New files are marked **new**.

| File | Purpose |
|---|---|
| [routes/web.php](../routes/web.php) | Guest form/submitted routes and manager setting/review/process routes, with role constraints and existing authority guards. |
| [SignupController.php](../app/Http/Controllers/Auth/SignupController.php) — **new** | Render signup/waiting pages, coordinate validated submissions and safe duplicate/closure feedback. |
| [SignupRequestController.php](../app/Http/Controllers/Admin/SignupRequestController.php) — **new** | Coordinate manager setting changes, confirmation previews and confirmed processing. |
| [UserController.php](../app/Http/Controllers/Admin/UserController.php) | Supply independent pending pagination, counts and settings to the existing dashboard. |
| [EnsureSignupIsOpen.php](../app/Http/Middleware/EnsureSignupIsOpen.php) — **new** | Friendly closed-page rejection before validation/limiting. |
| [SignupRequest.php](../app/Http/Requests/SignupRequest.php) — **new** | Reuse account validation, normalize username, reject authority injection and whitelist password-free flashed input. |
| [SignupManagementRequest.php](../app/Http/Requests/SignupManagementRequest.php) — **new** | Common active-manager authority and isolated `signupManagement` errors with no flashed token. |
| [ReviewSignupRequestsRequest.php](../app/Http/Requests/ReviewSignupRequestsRequest.php) — **new** | Validate action, single/all scope and permitted request ID. |
| [ProcessSignupRequestsRequest.php](../app/Http/Requests/ProcessSignupRequestsRequest.php) — **new** | Accept the encrypted confirmation without trusting submitted action/role/cohort fields. |
| [UpdateSignupSettingRequest.php](../app/Http/Requests/UpdateSignupSettingRequest.php) — **new** | Validate explicit enabled/disabled state. |
| [PendingSignupRequest.php](../app/Models/PendingSignupRequest.php) — **new** | Pending data persistence, hidden password/hash cast, date cast and display helpers. |
| [SignupSetting.php](../app/Models/SignupSetting.php) — **new** | Persist each role's independent boolean setting. |
| [SignupService.php](../app/Services/SignupService.php) — **new** | Submission, locked switch updates, bounded confirmations, approval/conflicts and pending-only deletion. |
| [ManagerTransaction.php](../app/Services/ManagerTransaction.php) | Document the shared account gate's guest-submission use while retaining fresh manager checks for manager writes. |
| [AppServiceProvider.php](../app/Providers/AppServiceProvider.php) | Register the shared 20/minute signup limiter and Hebrew 429 response. |
| [Signup migration](../database/migrations/2026_11_03_000000_create_signup_tables.php) — **new** | Add two InnoDB tables and disabled worker/manager settings without creating users. |
| [guest.blade.php](../resources/views/layouts/guest.blade.php) | Allow scoped signup styles through the guest layout's style stack. |
| [signup.blade.php](../resources/views/auth/signup.blade.php) — **new** | Identical role-specific forms without a logo, optional birthday and clear password guidance. |
| [signup-closed.blade.php](../resources/views/auth/signup-closed.blade.php) — **new** | Friendly closed-registration view without form or signup link. |
| [signup-submitted.blade.php](../resources/views/auth/signup-submitted.blade.php) — **new** | Separate waiting/contact-management page without credentials or signup links. |
| [Signup panel](../resources/views/admin/signups/panel.blade.php) — **new** | Independent switches, above-list bulk controls, mixed-role cards and ten-per-page navigation. |
| [Signup confirmation](../resources/views/admin/signups/confirm.blade.php) — **new** | Native single/bulk confirmation with role counts, permissions, irreversible-deletion text and cancel action. |
| [Admin index](../resources/views/admin/users/index.blade.php) | Include the real signup section and scoped stylesheet without signup navigation links. |
| [Receipt panel](../resources/views/admin/receipts/panel.blade.php) | Preserve `signup_page` when changing the receipt-report month, alongside existing independent pagination parameters. |
| [signup.css](../public/css/signup.css) — **new** | Scoped responsive form/card/switch/list/confirmation styles using shared tokens. |
| [SignupRequestTest.php](../tests/Feature/SignupRequestTest.php) — **new** | MariaDB-backed signup, validation, authority, confirmation, privacy, settings and dashboard scenarios. |
| [SignupConcurrencyTest.php](../tests/Feature/SignupConcurrencyTest.php) — **new** | Independent-process transaction races using the dedicated MariaDB schema. |
| [AGENTS.md](../AGENTS.md) | Confirmed policies and the narrow pending-request exception to the old no-self-signup rule. |
| [ROADMAP.md](../ROADMAP.md) | Dated authorization, scope, verification and unchanged review gates. |
| [README.md](../README.md) | Concise applicant/manager overview and documentation index. |
| [DEVELOPMENT.md](DEVELOPMENT.md) | Manual acceptance and request-to-code entry point. |
| [EXTRA-FEATURES-REPORT.md](EXTRA-FEATURES-REPORT.md) | Follow-up index and result summary. |
| [DEPLOYMENT.md](DEPLOYMENT.md) | Migration, confidential backup/restore tables and restored-switch checks. |
| [PI-INSTALLATION.md](PI-INSTALLATION.md) | Closed-by-default signup note and operator-guide link after first-manager setup. |
| [ACCOUNT-SIGNUP.md](ACCOUNT-SIGNUP.md) — **new** | Complete specification, Hebrew labels, operator instructions, code path and verification. |

No JavaScript, Composer dependency, first-manager/import command, existing user schema or prototype is changed for signup.

## Verification record

The complete dedicated MariaDB suite and focused signup/browser checks passed. Results below describe this delivery; earlier reports retain their historical totals.

| Check | Actual result |
|---|---|
| Full regression suite: `php artisan test --compact --stop-on-failure --stop-on-error` | **720 passed / 5262 assertions / 115.22 seconds**, zero failures/skips, exit status 0. |
| Focused signup MariaDB suite | **86 passed / 574 assertions / 17.72 seconds**, zero failures/skips: 72 feature cases and 14 independent-process race cases. |
| Chromium UI checks | **453 passed / 453 checks**, zero failures across 320/375/700/1440 px and JavaScript-disabled operation. |
| Browser fixture generation | Twelve synthetic snapshots rendered from the real Blade views/assets with a SQL execution guard; no database query or real request mutation was used to generate them. |
| `vendor/bin/pint --test` | Passed across the project. |
| `php artisan view:cache` | Passed. |
| `php artisan route:list` inspection | Guest/signup-open/throttle guards and manager authentication/session/role guards verified. |
| `composer validate --strict` | Passed. |
| Documentation file/heading links | All **241** local links/anchors passed. |
| `git diff --check` | Passed. |
| Local application migration | `php artisan migrate --no-interaction` applied the additive signup migration in **39.28 ms**. Both initial switches remain false; no real registration was enabled and no real user created. |
| Final read-only local state check | Worker signup **false**, manager signup **false**, pending requests **0**. |

The feature tests cover both default-closed switches, independent opening/closure, stale forms, fixed roles, optional birthdays, Hebrew/literal/hash-looking passwords, generic duplicate feedback across both pending roles and retained accounts, CSRF, the shared invalid-attempt limiter, password-free flashes, unlinked signup forms, independent ten-per-page mixed lists, scoped manager errors, individual/all-page decisions, frozen cohorts excluding later arrivals, direct account/import username conflicts, pending-only deletion/resubmission, token tampering/expiry/actor/version boundaries and transaction rollback. Seven concurrency groups run in both process orders, testing submission/closure, duplicate requests, trusted account creation/import and competing pending decisions under the MariaDB gate.

The initial focused attempt completed **zero assertions** because the local MariaDB process was stopped. Restarting its existing persistent data directory without a reset resolved the connection refusal. Two test fixtures were corrected before the passing run: preservation comparisons now use fresh database row snapshots so types/defaults/column order agree, and switching synthetic manager browsers flushes the old session's password fingerprint. No signup production defect was found in those corrections, and no automated test failure remains unresolved.

After the full suite had already passed, a normal local MariaDB shutdown interrupted the first read-only application-state check. A detached restart did not remain available; restarting the same existing data directory in a foreground terminal allowed that read-only check to pass. This process-lifecycle interruption did not invalidate the completed regression result or require a schema/data reset. The final check confirmed both real registration settings were disabled and there were zero pending requests.

Chromium checks cover both open role forms, closed/submitted pages, mixed pending cards/settings, role counts and permissions in native approval/deletion confirmations, controls above the list, no signup hyperlinks, keyboard/password-reveal behavior, narrow/long-content wrapping and forms without JavaScript. Evidence is under `/tmp/shift-signup-preview/browser-evidence.json`, outside Git. These checks use genuine Blade-rendered **static synthetic snapshots**; live HTTP authorization and database mutations are covered by the MariaDB suite, not claimed as browser mutations. Mobile form/dashboard and desktop confirmation screenshots were also visually inspected. An independent backend/permission review found no blocking issue. No browser dependency was added to the application.

The new migration is applied only on the local development installation. Raspberry Pi deployment, real-world approval decisions, owner browser acceptance and final office-pilot sign-off remain pending. Stage checkboxes are unchanged. Local testing did not open either real signup page, create real users, reset real passwords or alter existing scheduling/history.

## Manual acceptance

Perform these steps on a disposable review installation with synthetic applicants. Do not enable live registration or approve unfamiliar real users merely to exercise the feature.

1. Apply migrations and confirm both settings start closed. Visit each path directly as a guest and see the friendly closed page. Check login, menus, dashboard, submitted/closed pages and the two forms: no hyperlink should lead to either signup form.
2. Open only worker signup as manager. Confirm the other page remains closed, the worker page uses the login style without any SVG, and the 12-character password help is visible. Test keyboard access, password reveal and mobile widths. Birthday is optional; malformed/future supplied dates fail validation.
3. Submit a worker and a manager through their respective open forms. Confirm normalized usernames, separate waiting page, fixed roles and no login before approval. Matching full names with different usernames pass; pending/existing/inactive username duplicates and tampered role/activation input fail safely. Errors never repopulate a password.
4. Open a form, close that role's registration in another window and submit the stale form. No pending row should be created. Existing requests remain available for review, and reloading/restarting preserves each independent switch.
5. With more than ten synthetic requests, verify combined oldest-first pagination and that all-role action controls are above the list. Review **אישור כל הבקשות** from page 2: counts must include all pages and separate workers/managers, with full manager-permission text and no extra checkbox. Submit a later request after preview and confirm it stays pending after processing the earlier cohort.
6. Individually approve one synthetic request and bulk approve mixed roles. Both types become active with their original chosen password, correct role/destination and no required password change. Approved pending records disappear. Existing users/history remain unchanged.
7. Introduce a username conflict using trusted account creation after signup. Bulk approval should approve the other requests, retain the conflict with an explanation and preserve the existing account's fields/password. A wholly unexpected transaction failure is covered by automated rollback tests rather than manually sabotaging a real installation.
8. Individually/bulk delete synthetic requests. Confirm exact role counts, irreversible-deletion wording and no existing-user deletion. Later arrivals remain pending; resubmission of a deleted username is allowed while open. Request retention has no automatic expiry.
9. Verify stale/repeated confirmations do not recreate processed requests. Confirmations from another manager, expired tokens, tampered action/IDs, revoked sessions and worker/guest management requests must be rejected. Existing last-manager protection and account password-reset/deactivation behavior remain intact.
10. Repeat primary forms with JavaScript disabled. Check RTL at 320/375/700/1440 px, visible focus, field labels/error messages, long names and no page overflow. On a test installation only, exceed the shared 20/minute IP POST limit and check safe Hebrew 429 feedback without creating excess requests.

## Limits and deferred work

The feature has no email/SMS, applicant status lookup, invitation code, role editing, CAPTCHA service, IP/username ban, automatic expiry/purge, scheduled job, queue or external signup package. Existing authentication, manager authority, first-manager CLI, worker importer, role privileges and thirty-day inactivity session policy remain in place. Signup does not grant workers manager privileges or allow unapproved accounts into reports.

The no-hyperlink requirement controls application navigation; knowing a signup address still permits requesting that role while the page is open. Manager approval remains the authority boundary. All new settings/pending data are ordinary confidential MariaDB state and must be backed up with the application. Device-specific Apache/HTTPS, office-network, off-device recovery and resource evidence remain subject to the outstanding Stage 7 Raspberry Pi review.
