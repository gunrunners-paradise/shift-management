# מערכת נוכחות — Stage 6

A Laravel MVC application with a Hebrew RTL interface, MariaDB persistence, session authentication, manager-controlled accounts, worker scheduling, profiles, events, monthly receipts and desk reservations. Stage 6 adds a manager's explicit CSV download of scheduled workdays. The calendar keeps its confirmed order: calendar, receipts, then other information; manager event/block lists show the current month and all future months. Stage 6 is supplied for review; Stage 7 remains locked.

See the [extra features and follow-up report](docs/EXTRA-FEATURES-REPORT.md) for additions and corrections made between stages, with links to existing detailed reports.

## Run in this workspace

PHP 8.3+, Composer 2, and MariaDB are required. CSS and vanilla JavaScript are served directly from `public/`; no Node build is needed.

This prepared workspace stores its MariaDB data persistently in `.local/mariadb/data/`. Private `.env` and `.env.testing` files connect to separate application and test databases through `.local/mariadb/mariadb.sock`. The local instance runs as the owning Linux user with no TCP listener; you do not need the system MariaDB administrator password to start it. Preserve the existing environment files and `APP_KEY`.

From the repository root:

```bash
bash scripts/local-database.sh start
php artisan config:clear
php artisan migrate
php artisan app:create-manager
php artisan serve --host=127.0.0.1 --port=8000
```

Run the manager command in your own private interactive terminal. Choose a username and a password of at least 12 characters and no more than 72 UTF-8 bytes. Password and confirmation are hidden; the command prints only the username, manager role, login URL, and sign-in instructions. Open **http://127.0.0.1:8000/login** and use the credentials you chose. Repeating setup preserves the existing manager and password. There are no default credentials or public registration route.

Login sessions expire after **30 days of inactivity** (`SESSION_LIFETIME=43200` minutes). Application requests renew the timeout; explicit logout or account deactivation ends access immediately.

After a reboot, run `bash scripts/local-database.sh start` before starting Laravel again. The database does not start automatically. Use `bash scripts/local-database.sh status` to check it and `bash scripts/local-database.sh stop` for a graceful shutdown. Stopping it preserves the data; deleting the project or `.local/` deletes that local data. `.local/` is ignored by Git, so keep separate backups.

For a fresh checkout, run `composer install` and provision a MariaDB database and private environment files using [the setup guide](docs/DEPLOYMENT.md); the start script manages an already initialized local instance. The guide also covers Raspberry Pi, Apache, HTTPS and backup/restore setup. Laravel's development server is for local review.

If login returns HTTP 500 after a reboot and the log reports a missing database socket, start the local database, run `php artisan config:clear`, and refresh. See [workspace troubleshooting](docs/DEPLOYMENT.md#prepared-workspace-database) for details.

## Review Stage 6

1. Sign in as manager, use the `הורדת CSV` quick action, select an inclusive start/end date no later than today, and click the download button. Opening the dashboard or changing dates alone does not generate a report. The default range is the current month through today, but historical ranges are available.
2. Open the downloaded CSV in your preferred spreadsheet software. Check the Hebrew columns, in order: `שם העובד`, `מספר ימי עבודה`, `תאריכי עבודה`. Each retained worker appears, including inactive workers and workers with **0** days. Zero-day rows have an empty date cell; managers are excluded.
3. Compare a known worker's non-canceled shifts with the report. Each selected date counts once for any of the five periods. Dates are chronological `DD/MM/YYYY`, separated by comma and space within one cell. Canceled/replaced history does not add days, and desk reservations do not add workdays. Permanently deleted accounts/history are absent.
4. Try a reversed range, a missing date and a future date. Hebrew validation should appear without downloading a partial file. Check keyboard access and the native form on mobile and with JavaScript disabled. Workers cannot access the manager download endpoint.

Stage 6 needs no migration, new dependency or scheduled task. Reports are generated only for an explicit manager request and are not saved in application storage, emailed or produced periodically. The download uses UTF-8 BOM and quoted/escaped cells. See [the Stage 6 report](docs/STAGE6-REPORT.md) for changed files, actual checks, formula-prefix handling and the request reading guide. Spreadsheet acceptance remains part of owner review.

## Existing Stage 5 checks

Run `php artisan migrate` when updating another installation. Stage 5 adds `desks` and `desk_reservations`, and creates the nine initial desks once. Existing users, shifts and receipts stay in place.

1. Sign in as a worker and choose `עמדות` in the hamburger menu. Select a Sunday–Thursday date within the next 28 days, then choose a desk and a time interval of at least one hour, aligned to half hours, between 09:00 and 16:30. If today's office hours have ended, use a future working day. A shift is not required.
2. Save, refresh and check your reservation. Before its start, change the desk or times through the edit form. The previous row appears in canceled history. Expand the cancellation control, confirm and cancel; the slot becomes available for a new reservation. After the start, edit/cancel controls disappear and direct requests are rejected too.
3. Use two independently signed-in worker windows to request the same desk/time. Exactly one should succeed. Different workers can book adjacent intervals such as 09:00–10:00 and 10:00–11:00. A worker cannot hold two active reservations on one date, even on different desks or at disjoint times.
4. As manager, use `ניהול עמדות` in the dashboard. Add a disposable desk, rename it and check that its stable ID stays the same. Expand `מחיקת העמדה`, acknowledge the warning and delete it. The desk and every reservation linked to it, including past/canceled history, are permanently deleted; other workers' records on other desks remain. Refreshing a worker's saved desk link selects another active desk safely. There is no desk deactivation action.
5. On a disposable date, create a shift and a desk reservation, then block that date as manager. Both are canceled, and all desk cards become visibly disabled and cannot be selected. Removing the block enables desk choices again but restores neither reservation nor shift; the worker must choose and book again. Deactivating a disposable worker also cancels future/running reservations and ends account access. Account reactivation is available from the inactive account card; permanent desk deletion cannot be reversed.
6. Check desktop/mobile RTL, keyboard access and the same forms with JavaScript disabled. Busy desk intervals disclose times and an own-booking marker, without coworkers' private identity. Managers have no worker desk link and receive 403 on worker desk endpoints.

The suite simulates booking-start/end boundaries and independent MariaDB races without changing the host clock. See [the Stage 5 report](docs/STAGE5-REPORT.md) for changed files, actual checks and the route/controller/service/view reading guide.

## Permanently delete a desk

In `/admin`, use `ניהול עמדות`, expand `מחיקת העמדה` on the chosen desk, acknowledge the warning and submit. No username or password entry is required. Deletion removes the desk and **all its reservations**, including past, completed, canceled and in-progress records. It does not delete worker accounts, shifts, receipts or other desks' reservations. Active desks and desks left inactive by the earlier workflow can both be explicitly deleted; existing desks are not automatically purged.

This replaces desk deactivation and needs no migration. The original restrictive foreign keys remain in force; the service deletes related reservations before the desk in one transaction. Legacy inactive-state columns remain for existing databases. See [the desk deletion report](docs/DESK-DELETION-REPORT.md) for the request path, race checks and manual review steps.

## Reactivate an inactive account

As a manager, open `/admin` and click `הפעלת חשבון מחדש` on the inactive account. No username or password entry is required. The worker or manager can then sign in again with the same credentials. The account keeps its ID, role, profile, receipt records and history; canceled shifts and desk reservations stay canceled and must be booked again. Previously revoked login sessions stay invalid. Permanently deleted accounts cannot be reactivated.

Run `php artisan migrate` when updating another installation. The reactivation migration adds a session version to existing accounts without changing their passwords or active status. See [the reactivation report](docs/ACCOUNT-REACTIVATION-REPORT.md) for the code path, checks and manual review steps.

## Permanently delete an inactive account

As a manager, open account management in `/admin`. For an inactive account, expand `מחיקה`, acknowledge the permanent removal of its history, and submit. No username or password entry is required. Active accounts must first be deactivated. Deactivation preserves history; permanent deletion removes the account, its completed/canceled shifts, desk reservations, monthly receipts, sessions and profile photo. Shared blocked dates, events, desks and other workers' records remain. There is no automatic purge.

Run `php artisan migrate` when updating another installation. The additional migration makes deleted manager identities optional on shared blocks/events; applying it does not delete accounts. See [the account deletion report](docs/ACCOUNT-DELETION-REPORT.md) for verification and safe manual review with a disposable account.

## Manager events and blocked dates

The manager dashboard lists events/manual holidays and blocked dates from the **first day of the actual current calendar month in Israel time onward**, including every future month and year, with ten records per page in each list, ordered by date. Only past months are hidden; earlier dates within the current month remain visible. The receipt-report month picker does not change these lists. The cutoff moves to the new month's first day on the next dashboard load after a month boundary.

Records from past months stay in the database. Future blocks appear in the manager's list and continue to prevent shift and desk bookings. A separate archive is a possible future feature recorded in `AGENTS.md`; it has no controls in the current interface.

For manual review, confirm existing past-month records are hidden and create disposable events for this month, next month and next year: all three should appear, using pagination when needed. Check that a future blocked date in the next month appears and remains blocked in the worker calendar and desk page. Pagination and totals should include the current month and all future dates, including when viewing receipts for a different month. Automated tests check month rollover without changing the host clock.

Code path: `GET /admin` → `Admin/UserController::index()` calculates the local current month's first day and queries `BlockedDate` and `CalendarEvent` with `date >=` that date before pagination → `admin/blocks/panel.blade.php` and `admin/events/panel.blade.php` render the lists and matching empty messages. This change needs no migration, route, service/model, CSS or JavaScript additions and performs no record deletion.

Verified after the future-month correction on 2026-10-22: `php artisan test --compact --stop-on-failure` passed **442 tests and 2627 assertions in 68.74 seconds**, with no failures/skips. The ten updated scenarios in `tests/Feature/DashboardMonthVisibilityTest.php` cover current/future month and year visibility, Israel month/year/leap-year rollover, retained hidden past records, independent pagination, receipt-filter independence and future block enforcement. The first sandboxed attempt could not connect to the MariaDB socket (zero assertions); the successful rerun used the dedicated test database with socket access. Pint, Blade compilation and `git diff --check` passed; all five reference HTML files remain untouched. Manual browser acceptance remains for owner review.

## Existing events and receipts checks

Run `php artisan migrate` when updating another installation; Stage 4 adds `calendar_events` and `monthly_receipts` without replacing existing accounts or shifts.

1. Sign in as a manager. Use the `אירועים וחגים` quick action to add an event or manually named holiday for today's date, with an optional description. Edit it through its details control, then check the confirmed removal flow with a disposable event.
2. In a worker window, refresh the calendar. Today's announcement appears below the grid alongside the other supplemental panels. An event for a different date stays hidden. Merely adding a holiday must leave today's shift choices available; blocking remains a separate manager action.
3. Directly below the worker calendar, before announcements, birthdays and coworkers, check `יש לי קבלות החודש` and save. Refresh and confirm it stays checked. Uncheck and save to record `אין קבלות`. A worker with no saved row also has no receipts; there is no unanswered state.
4. Browse another week: the receipts month must still be the actual current month. Workers can update only that month. A form left open into a new month is rejected and asks for a refresh.
5. In the manager dashboard, use `מעקב קבלות חודשי` and select a month. Check each worker's yes/no state and the totals. Inactive workers remain listed with a label; managers are excluded. Selecting a different month must not copy the current month's state.
6. Check desktop/mobile RTL, keyboard access to inline event editing, validation feedback, and saving with JavaScript disabled. With JavaScript enabled, today's announcements refresh with the existing roughly 30-second polling. Polling must preserve an unsaved receipts checkbox and leave the calendar intact.

The MariaDB suite simulates separate months, Israel midnight and stale forms without changing the machine clock or real worker data. See [the Stage 4 report](docs/STAGE4-REPORT.md) for the file inventory, actual checks and route-to-view reading guide.

## Existing profile checks

1. Sign in and choose `הפרופיל שלי` from the account menu. Both workers and managers can edit their own name, gender, birth date and photo. Username and role remain read-only. Check the form and shared header at desktop and mobile widths.
2. Upload a JPG, PNG or WebP image up to 2MB and 2048 × 2048 pixels. Save and confirm the new photo appears in the profile and header. Replace it once; the previous file is removed after the database commit. Try an oversized file or an SVG to check Hebrew validation feedback.
3. Use the separate password form. An incorrect current password must leave the password unchanged. A valid new password requires at least 12 characters, at most 72 UTF-8 bytes, and matching confirmation. A successful change keeps the current browser signed in and ends other sessions; verify using a separate browser/profile or private window signed in independently (ordinary windows share a session).
4. Open the worker calendar. Birthdays and `מי עובד איתי היום?` appear below the grid; a birthday banner appears only when active workers have a matching birthday today. February 29 birthdays appear on February 28 in non-leap years. No age or birth year is shown to coworkers.
5. With two workers, select today's shifts and check the coworker list, including the logged-in worker. Cancel one selection and wait about 30 seconds in the other visible window. The list should update while the calendar remains intact. Browsing a different week must leave these panels tied to actual today.
6. Check the profile forms and initial calendar panels with JavaScript disabled. Managers retain their administration-only home and cannot access worker scheduling or its JSON endpoint.

Uploaded photos live privately under `storage/app/private/avatars/` and are served through authenticated `/avatars/{user}` requests. No public storage link is needed. Only your own photo or an active worker's team photo is accessible. Back up these private files together with MariaDB and the original environment key. See [the Stage 3 report](docs/STAGE3-REPORT.md) for changed files, checks and the request reading guide.

## Existing scheduling checks

1. Sign in as your existing manager and create a worker if needed. In a private browser window, sign in as the worker; it now opens the Sunday–Thursday calendar with five period rows.
2. Choose today's or a future available shift, replace its period, then choose `בלי משמרת`. Only one period per date should be purple; its other four choices remain clickable. Repeat with JavaScript disabled to exercise the native forms.
3. Browse previous/next/current weeks. Past dates and dates beyond today + 28 calendar days are read-only. Today's shifts remain editable for the entire day. Check the calendar and dialog at desktop and mobile widths.
4. In the manager window, use `חסימת ימי עבודה`, choose today or a future date, enter an optional reason and confirm. Refresh the worker calendar: all five cells for that date should be red and disabled. Confirm removing the block does not restore the canceled shift; choose a new one explicitly.
5. With a disposable worker, create a future shift and, during office hours, an upcoming or in-progress shift today. Deactivate the worker from the manager window: these shifts are canceled and the worker's open session is rejected. Completed shifts remain in history. Reactivating the account later restores login access, while canceled shifts remain canceled.
6. Verify role boundaries: `/admin` returns 403 for workers and `/worker` returns 403 for managers. Existing account creation, login/logout, last-manager protection, menus and password visibility remain available.

See [the Stage 2 report](docs/STAGE2-REPORT.md) for cancellation history, locking details, actual verification and the complete file inventory.

## Automated verification

Tests run against a **separate MariaDB database whose name ends in `_test`**. They erase that test schema. `tests/TestCase.php` checks the environment, schema suffix and actual MariaDB server before migration traits execute.

The prepared workspace already has private test configuration. Keep its credentials and key:

```bash
bash scripts/local-database.sh start
php artisan config:clear
php artisan test
vendor/bin/pint --test
composer validate --strict
composer check-platform-reqs
```

For a fresh test environment, follow [the test database setup](docs/DEPLOYMENT.md#mariadb-test-environment) before running the suite.

The race tests need PHP `pcntl` and local socket pairs; missing support is reported as a skipped test. They use independent committed MariaDB connections, not SQLite. Do not run two copies of the suite against the same test schema simultaneously.

## Read the code

Start in [routes/web.php](routes/web.php). `GET /login` calls `AuthenticatedSessionController::create()` and renders `auth/login.blade.php`. Its ordinary CSRF-protected form posts to `store()`. `LoginRequest::authenticate()` rate-limits attempts and asks Laravel's session guard to check the active `User` and password hash in MariaDB. The controller rotates the session and redirects to the role's home route.

For account creation, `POST /admin/users` passes through authentication, active-account and manager middleware, then `StoreUserRequest` validates the fields. `Admin/UserController::store()` calls `AccountManagementService::create()`, which acquires a schema-specific MariaDB lock, rechecks the actor and persists a `User` in a transaction. The password is hashed by the model cast. The redirect reaches `index()`, which reads users and renders `admin/users/index.blade.php` through the shared authenticated layout. `public/js/app.js` enhances native dropdowns and password visibility; forms work without JavaScript.

For CSV, `POST /admin/reports/workdays` (`admin.reports.workdays`) → `ExportWorkdaysRequest` → `Admin/WorkdayReportController::__invoke()` → `WorkdayCsvReportService::prepare()`. The service rechecks the manager under a row lock and reads workers with their qualifying shifts in one consistent database query. It returns a temporary CSV stream; the controller streams and closes it after generation has committed. `admin/reports/panel.blade.php` renders the normal form and `public/css/reports.css` scopes its responsive layout. No feature JavaScript or database writes are involved.

For permanent deletion, `DELETE /admin/users/{user}` → `DeleteUserRequest` → `Admin/UserController::destroy()` → `AccountDeletionService::delete()`. The service rechecks the manager and inactive target under locks, deletes the target's owned records and sessions, clears shared actor references and deletes the `User` in one transaction. `AvatarStorage::delete()` runs after commit. The redirect returns to `admin/users/index.blade.php`; its inline confirmation uses native forms and needs no new JavaScript.

For reactivation, `PATCH /admin/users/{user}/reactivate` → `ReactivateUserRequest` → `Admin/UserController::reactivate()` → `AccountManagementService::reactivate()`. The existing manager transaction locks and rechecks both accounts, activates the target and advances its session version without restoring canceled records. `LoginRequest::authenticate()` stamps the version on a fresh login; `EnsureAccountIsActive::handle()` rejects a stale version. The dashboard renders the native form; no feature JavaScript is needed.

For the calendar, `GET /worker` calls `ShiftCalendarController::index()`, which reads only the authenticated worker's active `Shift` rows and the week's `BlockedDate` records. `worker/home.blade.php` and `components/shift-slot.blade.php` render the complete five-row table. `public/js/calendar.js` adds the selection dialog; native forms still work without it. The period definitions live only in `config/shifts.php`.

`GET /profile` calls `ProfileController::edit()` and renders `profile/edit.blade.php`. Its two forms call `update()` through `UpdateProfileRequest`, or `updatePassword()` through `UpdatePasswordRequest`. `ProfileService` locks the current user's row before saving personal fields or checking and changing the password. `AvatarStorage` writes validated images under random private filenames; `AvatarController::show()` checks access and streams them with an explicit image MIME type. `User::avatarUrl()` supplies the header/profile/team image URL. Shared `app.js` enhances password visibility; profile forms require no feature JavaScript.


`TodayInformationService::forToday()` reads real active-worker birthdays, non-canceled selections and manually entered announcements for today's Israel date. The calendar renders `worker/today.blade.php` below the grid; `GET /worker/today` calls `TodayInformationController` to return the same limited display fields as JSON. `public/js/today.js` refreshes only these panels about every 30 seconds while visible, and on return to the tab. It never rebuilds the calendar or changes an unsaved receipts checkbox.

Event forms submit to `admin.events.store`, `admin.events.update` or `admin.events.destroy`. `Admin/CalendarEventController` coordinates their Form Requests and `CalendarEventService`, which rechecks manager authority inside `ManagerTransaction` and writes `CalendarEvent` rows. `admin/events/panel.blade.php` and `fields.blade.php` render the native forms; events never call the date-blocking service.

`PUT /worker/receipts` → `UpdateMonthlyReceiptRequest` → `WorkerReceiptController::update()` → `MonthlyReceiptService::set()` locks the authenticated worker, rechecks the current Israel month and upserts that worker's boolean `MonthlyReceipt`. `worker/receipts.blade.php` renders the saved current-month choice below the grid. For managers, `GET /admin?receipts_month=YYYY-MM` → `AdminDashboardRequest` → `Admin/UserController::index()` → `MonthlyReceiptService::report()` → `admin/receipts/panel.blade.php`. The report treats missing rows as false and keeps each month separate.

`GET /worker/desks` → `DeskPageRequest` → `DeskReservationController::index()` → `DeskAvailabilityService::forDate()` reads active desks, occupied intervals and the authenticated worker's history → `worker/desks.blade.php`. `public/css/desks.css` provides the responsive grid and booking panel. All desk controls use native links, forms and `<details>`; there is no feature JavaScript or client-stored booking state.

The worker's reservation forms call `DeskReservationController::store()`, `update()` or `destroy()` through their Form Requests. `DeskReservationService::book()`, `update()` and `cancel()` lock the worker, permanent date and desk identities, validate current availability/time and save or cancel `DeskReservation` records. Editing retains the old canceled row until an explicit permanent account or desk deletion removes it. The manager's `admin.desks.*` routes call `Admin/DeskController` → `DeskManagementService::create()`, `rename()` or `delete()` under `ManagerTransaction`; `admin/desks/panel.blade.php` renders the controls and reservation counts. `DELETE /admin/desks/{desk}` (`admin.desks.destroy`) passes through `DeleteDeskRequest` and `DeskController::destroy()` to delete all related reservations and then the desk. Read the desk deletion report for why this holds a desk lock without acquiring date locks afterward.

`POST /worker/shifts` and `DELETE /worker/shifts/{date}` pass through their Form Requests, then call `ShiftCalendarController::store()` / `destroy()` and `ShiftSchedulingService::select()` / `clear()`. The service locks the worker and date, checks the current rules and saves or cancels a `Shift`. `POST /admin/blocks` and `DELETE /admin/blocks/{date}` call `Admin/BlockedDateController` and `DateClosureService`, which closes or reopens a date transactionally. `admin/blocks/panel.blade.php` renders that dashboard section.

The migrations create `users`, `sessions`, `calendar_dates`, `blocked_dates`, `shifts`, `calendar_events`, `monthly_receipts`, `desks` and `desk_reservations`. `calendar_dates` supplies permanent date locks. Generated active-slot indexes permit canceled history while limiting each worker to one active shift and one independent active desk reservation per date. Desk reservations also have MariaDB office-hour, minimum-duration and half-hour CHECK constraints; transactions protect overlapping intervals. Receipts have a unique worker/month index and checks for canonical months/boolean values. `ManagerTransaction` shares the schema-specific account lock across manager writes. Read `AccountManagementService::deactivate()` for shift/desk cancellation, last-manager protection and session revocation, `EnsureAccountIsActive::handle()` for rejecting existing disabled sessions, and `CreateFirstManager::handle()` for secure bootstrap.

The [Stage 1 delivery report](docs/STAGE1-REPORT.md) records the earlier authentication/account delivery. The five root HTML prototypes and original logo remain read-only references; Apache must serve only Laravel's `public/` directory.
