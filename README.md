# מערכת נוכחות — Stage 4

A Laravel MVC application with a Hebrew RTL interface, MariaDB persistence, session authentication, manager-controlled accounts, worker scheduling, blocked dates and personal profiles. Stage 4 adds manually managed events/holidays and monthly receipts. All supplemental worker information appears below the calendar. Stage 4 is supplied for review; Stage 5–7 remain locked.

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

## Review Stage 4

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
5. With a disposable worker, create a future shift and, during office hours, an upcoming or in-progress shift today. Deactivate the worker from the manager window: these shifts are canceled and the worker's open session is rejected. Completed shifts remain in history. Do not deactivate an account you still need; reactivation is outside the implemented workflow.
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

For the calendar, `GET /worker` calls `ShiftCalendarController::index()`, which reads only the authenticated worker's active `Shift` rows and the week's `BlockedDate` records. `worker/home.blade.php` and `components/shift-slot.blade.php` render the complete five-row table. `public/js/calendar.js` adds the selection dialog; native forms still work without it. The period definitions live only in `config/shifts.php`.

`GET /profile` calls `ProfileController::edit()` and renders `profile/edit.blade.php`. Its two forms call `update()` through `UpdateProfileRequest`, or `updatePassword()` through `UpdatePasswordRequest`. `ProfileService` locks the current user's row before saving personal fields or checking and changing the password. `AvatarStorage` writes validated images under random private filenames; `AvatarController::show()` checks access and streams them with an explicit image MIME type. `User::avatarUrl()` supplies the header/profile/team image URL. Shared `app.js` enhances password visibility; profile forms require no feature JavaScript.


`TodayInformationService::forToday()` reads real active-worker birthdays, non-canceled selections and manually entered announcements for today's Israel date. The calendar renders `worker/today.blade.php` below the grid; `GET /worker/today` calls `TodayInformationController` to return the same limited display fields as JSON. `public/js/today.js` refreshes only these panels about every 30 seconds while visible, and on return to the tab. It never rebuilds the calendar or changes an unsaved receipts checkbox.

Event forms submit to `admin.events.store`, `admin.events.update` or `admin.events.destroy`. `Admin/CalendarEventController` coordinates their Form Requests and `CalendarEventService`, which rechecks manager authority inside `ManagerTransaction` and writes `CalendarEvent` rows. `admin/events/panel.blade.php` and `fields.blade.php` render the native forms; events never call the date-blocking service.

`PUT /worker/receipts` → `UpdateMonthlyReceiptRequest` → `WorkerReceiptController::update()` → `MonthlyReceiptService::set()` locks the authenticated worker, rechecks the current Israel month and upserts that worker's boolean `MonthlyReceipt`. `worker/receipts.blade.php` renders the saved current-month choice below the grid. For managers, `GET /admin?receipts_month=YYYY-MM` → `AdminDashboardRequest` → `Admin/UserController::index()` → `MonthlyReceiptService::report()` → `admin/receipts/panel.blade.php`. The report treats missing rows as false and keeps each month separate.

`POST /worker/shifts` and `DELETE /worker/shifts/{date}` pass through their Form Requests, then call `ShiftCalendarController::store()` / `destroy()` and `ShiftSchedulingService::select()` / `clear()`. The service locks the worker and date, checks the current rules and saves or cancels a `Shift`. `POST /admin/blocks` and `DELETE /admin/blocks/{date}` call `Admin/BlockedDateController` and `DateClosureService`, which closes or reopens a date transactionally. `admin/blocks/panel.blade.php` renders that dashboard section.

The migrations create `users`, `sessions`, `calendar_dates`, `blocked_dates`, `shifts`, `calendar_events` and `monthly_receipts`. `calendar_dates` supplies permanent date locks; the generated unique active-slot index allows cancellation history and only one active shift per worker/date. Receipts have a unique worker/month index and MariaDB checks for first-of-month keys and boolean values. `ManagerTransaction` shares the schema-specific account lock across manager writes. Read `AccountManagementService::deactivate()` for shift cancellation, last-manager protection and session revocation, `EnsureAccountIsActive::handle()` for rejecting existing disabled sessions, and `CreateFirstManager::handle()` for secure bootstrap.

The [Stage 1 delivery report](docs/STAGE1-REPORT.md) records the earlier authentication/account delivery. The five root HTML prototypes and original logo remain read-only references; Apache must serve only Laravel's `public/` directory.
