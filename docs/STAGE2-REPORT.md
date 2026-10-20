# Stage 2 delivery for review — 2026-10-20

Later progression note: the user explicitly authorized Stage 3 on 2026-10-20. This is the historical Stage 2 report; current profile and calendar-panel behavior is described in `docs/STAGE3-REPORT.md` and `README.md`.

Stage 2 implements worker shifts and manager blocked dates. The user explicitly authorized this stage and confirmed that worker deactivation must cancel an in-progress shift too. Stage 2 approval remains pending; Stage 3–7 are locked. This report describes the current implementation rather than marking the stage accepted.

## Implemented and deferred scope

- The worker landing page is now a server-rendered Sunday–Thursday calendar with exactly five configured periods, previous/next/current-week navigation, actual-today indicator, native selection/cancellation forms and an optional selection dialog.
- A worker can choose one active shift per local date, replace it, or clear it. Canceled rows retain the original date/period, actor, time and reason. Repeating the same selection or clearing an empty day is safe.
- The server enforces active worker ownership, the five period codes, Sunday–Thursday, today through today + 28 calendar days, and blocked dates. Today's selection remains editable for the whole day, including after its scheduled end. Other weeks can be browsed read-only. There is no worker capacity limit.
- Selected cells are purple, the other four available choices are pink and clickable, empty cells are white, and all five blocked cells are strong red and disabled. Visible text and accessible descriptions accompany colors.
- The manager dashboard retains account management and gains a real blocked-date section. Blocking today or a future date requires confirmation, accepts an optional reason, and cancels all active shifts on that date in the same transaction. Removing a block requires confirmation and never restores canceled shifts. Past blocked dates are displayed read-only. Managers cannot use worker scheduling routes.
- Deactivation cancels a worker's future shifts and today's upcoming or in-progress shift, disables the account and revokes stored sessions atomically. Completed shifts, including today's shift at or after its configured end time, remain scheduled in history. Earlier cancellations keep their original audit details.

Deferred: profiles/uploads, birthdays, coworkers, events/holidays, receipts, desks/reservations, CSV and deployment acceptance. No later-stage tables, routes, fake notices, demo controls or manager edits to an individual worker's shift were added. The five reference HTML files and original/public logo were verified unchanged.

## Changed files

| Area | Files |
| --- | --- |
| Scheduling definitions | `config/shifts.php` |
| Database and models | `database/migrations/2026_10_20_000000_create_shift_scheduling_tables.php`, `app/Models/Shift.php`, `app/Models/BlockedDate.php` |
| Transactions and business rules | New `app/Services/CalendarDateLock.php`, `ManagerTransaction.php`, `ShiftSchedulingService.php`, `DateClosureService.php`; updated `AccountManagementService.php` |
| Routes/controllers | `routes/web.php`; new `app/Http/Controllers/ShiftCalendarController.php`, `Admin/BlockedDateController.php`; updated `Admin/UserController.php`, `HomeController.php` |
| Request validation | New `app/Http/Requests/CalendarWeekRequest.php`, `StoreShiftRequest.php`, `ClearShiftRequest.php`, `BlockDateRequest.php`, `UnblockDateRequest.php`; updated `lang/he/validation.php` |
| Views | Updated `resources/views/worker/home.blade.php`, `admin/users/index.blade.php`, `components/header.blade.php`, `layouts/app.blade.php`; new `components/shift-slot.blade.php`, `admin/blocks/panel.blade.php` |
| Browser assets | New `public/css/calendar.css`, `blocked-dates.css`, `public/js/calendar.js`; removed obsolete worker-placeholder CSS and updated the dashboard comment in `public/css/app.css` |
| Tests | New `tests/Feature/ShiftSchedulingTest.php`, `ShiftCalendarTest.php`, `DateClosureTest.php`, `WorkerDeactivationShiftTest.php`, `ShiftConcurrencyTest.php`, `tests/Concerns/CreatesShiftRecords.php`, `tests/Support/ConcurrentProcesses.php`; updated `AccountManagementTest.php`, `CsrfProtectionTest.php` |
| Documentation | `AGENTS.md`, `ROADMAP.md`, `README.md`, `docs/DEPLOYMENT.md`, this report; a historical progression note in `docs/STAGE1-REPORT.md` |

## Follow a request through the code

**Read the calendar:** named route `worker.home` (`GET /worker`) → worker/active-account middleware → `CalendarWeekRequest` → `ShiftCalendarController::index()`. It normalizes the requested week to Sunday, reads only the authenticated worker's active `Shift` rows and that week's `BlockedDate` records, then renders `worker/home.blade.php` through the shared layout/header. `components/shift-slot.blade.php` builds each form and status. Period codes, labels, times and durations come from `config/shifts.php`. `calendar.css` arranges the table; `calendar.js::initializeShiftDialog()` enhances the already-rendered forms without generating the grid or storing booking state.

**Choose or clear:** `worker.shifts.store` (`POST /worker/shifts`) → `StoreShiftRequest` → `ShiftCalendarController::store()` → `ShiftSchedulingService::select()`. `worker.shifts.destroy` (`DELETE /worker/shifts/{date}`) → `ClearShiftRequest` → `destroy()` → `ShiftSchedulingService::clear()`. Ownership is taken from the session, and the DELETE route date takes precedence over submitted body values. The service rechecks the active worker and business date under locks. Replacement calls `Shift::cancel()` before inserting the new row. The controller redirects to the viewed week and displays a Hebrew result message. All forms use CSRF and work without JavaScript.

**Close or reopen:** `admin.blocks.store` / `admin.blocks.destroy` → manager middleware → `BlockDateRequest` / `UnblockDateRequest` → `Admin/BlockedDateController::store()` / `destroy()` → `DateClosureService::block()` / `unblock()`. A closure creates `BlockedDate` and cancels active `Shift` rows together. Reopening removes only the active block. `Admin/UserController::index()` reads both paginated accounts and blocked dates; `admin/blocks/panel.blade.php` renders the new section. Its controls use ordinary forms without feature JavaScript.

**Deactivate:** the existing account route calls `AccountManagementService::deactivate()`. It locks the target user, calls `ShiftSchedulingService::cancelForDeactivation()`, disables the account and deletes database sessions within the shared manager transaction. `EnsureAccountIsActive` still rejects a disabled account on subsequent requests even if an overlapping request rewrites its old session.

## Database integrity and lock order

The migration adds only three Stage 2 tables:

1. `calendar_dates`: a permanent DATE primary key used to serialize writes for a date. `CalendarDateLock::acquire()` uses a no-op upsert and an exclusive row lock within the surrounding transaction. The row exists independently of `blocked_dates` and remains after reopening, so two first-ever requests cannot bypass serialization by both finding no block row.
2. `blocked_dates`: active date blocks, optional reason and manager identity. A DATE foreign key references the permanent key.
3. `shifts`: original scheduled date/period plus nullable cancellation metadata. A stored generated `active_slot` is 1 for active records and NULL for canceled records. The unique worker/date/active-slot index prevents a second active shift while permitting any number of canceled historical rows. User/date foreign keys restrict deletion.

Workers lock **user → date → shift**. All manager writes share `ManagerTransaction`'s schema-specific MariaDB advisory lock, then a DB transaction. The manager is reloaded with a locking read, which avoids a stale repeatable-read snapshot before waiting for a worker. Blocking locks the date before its shifts. Deactivation locks the worker, gathers its active dates, locks those dates chronologically, then cancels the relevant shifts. This order also avoids introducing shift-to-date lock inversions. A single manager lock keeps account changes and cancellation-actor foreign-key locks from racing each other; workers do not acquire it. Every path releases the advisory lock after commit or rollback.

Date validation is repeated after lock waits. Deactivation also rereads the clock after acquiring its date locks, so a shift ending while the request waits is preserved as completed. Local calendar DATE values are separate from timestamp/end-time comparisons in `Asia/Jerusalem`. No period start-time cutoff is applied to a worker's same-day edits.

Stage 5 can extend the existing closure transaction with desk cancellation and use the same permanent date keys. No desk schema or booking implementation exists yet.

## Commands and actual verification

The application database was inspected before implementation: it contained only the Stage 1 application tables, migration records and an active manager. There were no legacy shift tables or period records to translate. The new migration was applied to the isolated test schema and then to the persistent local application database with `php artisan migrate`. Existing accounts were preserved; no application demo workers or shifts were inserted.

| Check | Actual result |
| --- | --- |
| `php artisan test --compact` | **121 passed, 743 assertions, no failures/skips**, 13.54 seconds; MariaDB 10.11.14 |
| Final calendar/account view regression run | **38 passed, 193 assertions**, after the dialog presentation and worker deactivation-warning changes |
| Independent-process MariaDB races | Passed: block versus selection on three first-ever dates; two choices for one worker; two workers selecting the same first-ever date; deactivation versus selection; blocking versus worker deactivation |
| Time/authorization/history coverage | Passed: five periods and legacy-code rejection, own records only, generated active uniqueness, replace/clear/rebook history, Fri/Sat/past/+29 rejection, inclusive +28 across DST, Israel local date, same-day late editing, deactivation boundaries and clock advancement during a date-lock wait |
| Enabled CSRF middleware | Passed for shift and closure mutations, plus existing login/account/logout flows |
| `php artisan migrate` | New scheduling migration applied to persistent local database successfully |
| `vendor/bin/pint --test` | Passed |
| `composer validate --strict` | Passed |
| `composer check-platform-reqs` | Passed on PHP 8.3.6 |
| `php artisan view:cache` | Passed |
| `php artisan route:list --except-vendor` | Twelve application routes, with no later-stage routes |
| Live HTTP smoke check | Existing local `/login` returned 200 after the migration |
| Browser layout inspection | Firefox calendar screenshots at 1440px, 390px and 320px; manager dashboard and blocked-date section at desktop/mobile widths; selection dialog inspected |
| Reference integrity | All five root HTML references unchanged; public logo matches supplied original |

The first full suite also passed. Code review corrected a potential stale snapshot before a worker-lock wait and moved deactivation's clock read after date-lock waits; the final full result above includes the time-boundary regression. Database and browser checks required approved execution outside the sandbox because local sockets are restricted there.

Browser layout inspection uses temporary unsaved users and rendered Blade data, without changing application records or publishing preview routes. The calendar retains all five rows, readable time labels and text status cues; very narrow displays scroll within the table region. The RTL header keeps its existing logo/hamburger/account placement. A Hebrew text-direction issue in the selection dialog's time range was corrected during inspection. Actual booking/closure behavior is verified by the MariaDB feature tests. The owner's keyboard/dialog and multi-window acceptance checks remain part of review.

## Manual acceptance and remaining issues

Follow the six steps in `README.md` under “Review Stage 2”: sign in as manager and worker in separate windows, choose/replace/clear, navigate weeks, block/reopen, deactivate a disposable worker, and verify role separation. Check mobile widths and try the native forms with JavaScript disabled. Existing setup commands remain `bash scripts/local-database.sh start`, `php artisan migrate`, and `php artisan serve --host=127.0.0.1 --port=8000`. Repeat manager bootstrap only if needed; it never resets an existing account.

No known failing automated check remains. User acceptance, real Raspberry Pi/Apache/HTTPS verification and backup/restore rehearsal remain outstanding at their appropriate review gates. Manager editing of individual worker shifts remains unauthorized. Stop here for Stage 2 review; do not start Stage 3 without an explicit instruction.
