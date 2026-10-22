# Stage 4 delivery for review — 2026-10-20

The user explicitly authorized Stage 4 and confirmed that workers may change receipts for the **actual current month only**. The existing instruction to keep all supplemental calendar information below the grid remains in force. These policies are recorded in `AGENTS.md` and `ROADMAP.md`. Stage 4 is supplied for review; Stage 5–7 remain locked and stage approval checkboxes remain unchanged.

Progression note: the user explicitly authorized Stage 5 on 2026-10-22. This report preserves the Stage 4 delivery record; current desk functionality and verification are documented in [the Stage 5 report](STAGE5-REPORT.md). This does not claim the owner independently completed every manual check below.

## Implemented and deferred scope

- Managers can create, list, edit and remove manually entered events or holidays. Each has a title, one local calendar date, an optional description and an event/holiday label. Removal requires explicit confirmation. Creator and last editor IDs are retained while the announcement exists.
- Events and holidays are informational. Their workflows never close a workday or cancel a shift; the existing manager date-blocking action remains independent. There is no automatic holiday import, recurrence or multi-day event behavior.
- Workers see today's genuine announcements below the calendar, alongside the existing below-grid birthday and coworker sections. Announcements use the actual Israel date independently of the week being browsed. Multiple matching notices are supported, and an empty announcement section is hidden.
- The current-month receipts panel appears first directly below the grid, before announcements, birthdays and coworkers, as confirmed in the follow-up layout review. Its saved state is a boolean: checked means receipts to send, unchecked or a missing row means no receipts. Native forms send an explicit false value for an unchecked checkbox. There is no unanswered state or receipt file upload.
- Workers write only their own actual current month, even while browsing another week. Past/future month submissions and stale forms crossing a month boundary are rejected. Managers cannot submit worker receipts or change another worker's state.
- The manager dashboard includes a selectable-month receipts report, a yes/no row per worker and consistent yes/no/total counts. Missing receipt rows count as no. Inactive worker accounts remain visible with a label so their saved history remains readable; manager accounts are excluded.
- Manager quick actions link to the real events and receipts sections. Existing authentication, accounts, profile, shifts, blocks, shared header and profile-dropdown navigation remain intact.
- Initial calendar information and all new forms work without JavaScript. The existing 30-second polling refresh now includes announcements. It updates only today's information panels, leaving the calendar and any unsaved receipts choice alone. Text is escaped in Blade and inserted through DOM text nodes in JavaScript.

Deferred: desks and reservations, CSV, multi-day/recurring events, imported holidays, prior-month receipt editing, manager receipt overrides and deployment acceptance. No later-stage tables, routes or placeholder controls were introduced. Desk behavior cannot be exercised until Stage 5; events contain no booking or blocking side effects.

## Changed files

| Area | Files |
| --- | --- |
| Database/models | New `database/migrations/2026_10_20_000001_create_events_and_receipts_tables.php`, `app/Models/CalendarEvent.php`, `MonthlyReceipt.php` |
| Business workflows | New `app/Services/CalendarEventService.php`, `MonthlyReceiptService.php`; updated `TodayInformationService.php`; clarified `ManagerTransaction.php` comment |
| Controllers/routing | New `app/Http/Controllers/Admin/CalendarEventController.php`, `WorkerReceiptController.php`; updated `Admin/UserController.php`, `ShiftCalendarController.php`, `routes/web.php` |
| Validation | New `app/Http/Requests/AdminDashboardRequest.php`, `StoreCalendarEventRequest.php`, `DeleteCalendarEventRequest.php`, `UpdateMonthlyReceiptRequest.php`; updated `lang/he/validation.php` |
| Blade | New `resources/views/admin/events/panel.blade.php`, `fields.blade.php`, `admin/receipts/panel.blade.php`, `worker/receipts.blade.php`; updated `admin/users/index.blade.php`, `worker/home.blade.php`, `worker/today.blade.php`, `components/field.blade.php` |
| Browser assets | New `public/css/events.css`, `receipts.css`; updated `app.css`, `calendar.css`, `today.css`, `public/js/today.js` |
| Tests | New `tests/Feature/CalendarEventTest.php`, `MonthlyReceiptTest.php`, `MonthlyReceiptConcurrencyTest.php`; updated `AccountManagementTest.php`, `CsrfProtectionTest.php`, `ShiftCalendarTest.php` |
| Documentation | `AGENTS.md`, `ROADMAP.md`, `README.md`, `docs/DEPLOYMENT.md`, a progression note in `docs/STAGE3-REPORT.md`, this report |

The shared field component now supports showing errors only on the failed inline event form and safely redisplays rejected non-scalar input. Feature CSS remains scoped; the reusable screen-reader helper is shared rather than tied to the calendar stylesheet. All five root HTML references remain read-only.

## Follow a request through the code

**Create or edit an announcement:** `admin.events.store` (`POST /admin/events`) or `admin.events.update` (`PATCH /admin/events/{calendarEvent}`) → authentication, active-account, session and manager middleware → `StoreCalendarEventRequest` → `Admin/CalendarEventController::store()` / `update()` → `CalendarEventService::create()` / `update()` → `CalendarEvent`. The service uses the existing `ManagerTransaction`, which rechecks the manager under locks before committing. Updates reload and lock the target event and preserve its creator. Only explicitly allowed fields are assigned; submitted actor IDs and date ranges are prohibited. The controller redirects to the dashboard's events anchor. `admin/events/panel.blade.php` and `fields.blade.php` render native forms and inline `<details>` editing, styled by `events.css`.

**Remove an announcement:** `admin.events.destroy` (`DELETE /admin/events/{calendarEvent}`) → `DeleteCalendarEventRequest` requires the confirmation checkbox → `CalendarEventController::destroy()` → `CalendarEventService::delete()`. Only the announcement is removed; independent blocked dates and shifts are untouched.

**Read today's notices:** `worker.home` (`GET /worker`) → `ShiftCalendarController::index()` → `TodayInformationService::forToday()` reads `CalendarEvent` rows for actual today, alongside existing birthdays and planned coworkers → `worker/home.blade.php` includes `worker/today.blade.php` after the grid. `worker.today` (`GET /worker/today`) → `TodayInformationController::__invoke()` → the same service → limited JSON fields. `public/js/today.js::initializeTodayInformation()` maintains the polling lifecycle; `renderTodayAnnouncements()` changes only the notice list with text nodes. It never creates calendar cells or changes receipts input.

**Save receipts:** `worker.receipts.update` (`PUT /worker/receipts`) → worker middleware → `UpdateMonthlyReceiptRequest` → `WorkerReceiptController::update()` → `MonthlyReceiptService::set()`. The service takes the user from the authenticated session, locks and rereads that worker, rechecks authority and the current Israel month, then inserts or updates the single `MonthlyReceipt` boolean for that worker/month. The controller redirects to the viewed calendar week and receipts anchor. `worker/receipts.blade.php` renders the saved actual-current-month choice through a standard CSRF-protected form; no feature JavaScript is needed.

**Read a monthly report:** `admin.users.index` (`GET /admin?receipts_month=YYYY-MM`) → `AdminDashboardRequest` → `Admin/UserController::index()` → `MonthlyReceiptService::report()`. One left join reads all worker identities with the selected month's optional row. The same result supplies both the list and counts, with missing rows treated as false. `admin/receipts/panel.blade.php` renders the month picker, summary and worker table. Existing paginated dashboard lists preserve the selected month in their links.

## Database integrity and time boundaries

The Stage 4 migration adds exactly two InnoDB tables. `calendar_events` stores a single DATE and manager foreign keys. `monthly_receipts` stores the worker foreign key, canonical first-of-month DATE and a non-null boolean. A unique worker/month index prevents duplicate states; MariaDB CHECK constraints enforce a first-day month key and values limited to 0/1. Foreign keys restrict deletion rather than erasing user history.

Receipt writes lock **worker → receipt** within one transaction. Locking the existing user row serializes simultaneous first writes even when no receipt row exists, and shares the account-deactivation lock. Deactivation either follows a committed receipt or causes the waiting receipt write to reject the now-inactive worker; it never erases earlier receipt states. The database unique key is an additional integrity guard. The service checks the actual month again after acquiring the lock, so a request waiting across Israel midnight cannot write a month that has just become historical.

Manager event maintenance reuses the existing schema-specific manager advisory lock and transaction, including an active-role reread. Event updates/deletes lock the current event row. No calendar-date closure lock is needed because announcements do not affect availability. All local dates/months remain separate from timestamps. No environment credentials, account passwords or session settings were changed.

## Commands and actual verification

Commands used:

```bash
php artisan test --compact
php artisan migrate
vendor/bin/pint --test
composer validate --strict
composer check-platform-reqs
php artisan view:cache
php artisan route:list --except-vendor
git diff --check
```

Early full runs had **224 passing tests and three failures** in new malformed-array redisplay cases. Shared inputs, the description textarea and the checkbox now handle rejected array values without a rendering error. The remaining feedback assertions exposed a test setup issue: redirected requests needed the session cookie carried forward as a browser would. After correcting those tests, the focused malformed-input run passed **3 tests and 15 assertions**. Temporary diagnostic output was removed.

| Check | Actual result |
| --- | --- |
| Final complete MariaDB suite | **227 passed, 1348 assertions, no failures/skips**, 23.66 seconds, MariaDB 10.11.14 |
| Receipt integrity and time coverage | Passed: current month only, separate month rollover, Israel-versus-UTC boundary, clock advancement during worker-lock acquisition, boolean/default state, ownership and database constraints |
| Independent-process races | Passed: simultaneous first receipt writes and receipt write versus deactivation; all earlier account/scheduling/profile races included |
| Security and announcement coverage | Passed: manager/worker/guest/inactive authorization, enabled CSRF checks, escaped title/description, actual-today-only announcements, informational holidays and preserved shifts |
| `php artisan migrate` | Stage 4 migration applied successfully to persistent local application database; no demo records inserted |
| Pint, Composer validation/platform, Blade cache | Passed on PHP 8.3.6 |
| Routes | 21 intended application routes; four Stage 4 mutations, with no desk or CSV routes |
| Live HTTP smoke check | Existing local `/login` returned HTTP 200 after migration |
| Browser polling harness | **17/17 assertions passed** in each HTTP 401 and 403 run |
| Browser layout inspection | Worker and manager desktop/mobile screenshots checked; expanded event edit/delete controls checked at 320px |
| References and whitespace | All five root HTML references and original logo unchanged; public logo matches original; `git diff --check` passed |

Browser previews used unsaved models and rendered Blade data, without saving application accounts/events/receipts or publishing preview routes. Root inspected worker desktop/mobile, manager desktop/mobile sections, and expanded mobile event forms. The polling harness used the actual JavaScript with stubbed endpoint responses: announcements update as escaped text, empty notices hide/reappear, the grid and unsaved checkbox stay intact, hidden tabs pause, visible tabs refresh, failures retain earlier information, and authentication failure clears information and stops polling. MariaDB feature tests independently exercise the actual endpoint.

The suite uses the guarded separate MariaDB `_test` schema. It never substitutes SQLite. Independent-process races exercise simultaneous first receipt submissions and receipt writing versus worker deactivation; all earlier scheduling/account/profile race tests remain in the suite. Month and date tests simulate time without changing the host clock.

## Manual acceptance and remaining issues

Follow `README.md` under “Review Stage 4”: create/edit/remove a disposable announcement as manager; check that only today's notices appear below the worker calendar; confirm holidays alone leave shifts available; save both checkbox states; browse another week; and inspect current/previous months in the manager report. Use desktop/mobile RTL, keyboard access and native forms with JavaScript disabled. While polling is active, leave the receipts checkbox changed but unsaved and confirm it stays untouched.

Local commands remain `bash scripts/local-database.sh start`, `php artisan migrate`, and `php artisan serve --host=127.0.0.1 --port=8000`. Other installations must apply the new migration before loading Stage 4 pages. Include both new tables and their constraints in confidential database backups; there is no new file-storage requirement. The deployment guide retains the separate migration-account, Apache `public/`, HTTPS and off-device backup procedures.

No known failing check remains. Owner acceptance and actual Raspberry Pi/Apache/HTTPS/backup-restore verification remain pending. Stop after this Stage 4 delivery and wait for the user's review before authorizing Stage 5.
