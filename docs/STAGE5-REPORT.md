# Stage 5 delivery for review — 2026-10-22

The user explicitly authorized Stage 5 and confirmed that deactivating a desk must immediately cancel an in-progress reservation as well as future reservations. These rules are recorded in `AGENTS.md` and `ROADMAP.md`. Stage 5 is supplied for review; Stage 6–7 remain locked. The calendar still displays the calendar first, receipts directly below it, then announcements, birthdays and coworkers.

## Implemented and deferred scope

- The worker hamburger includes a real `עמדות` page. It follows the desk reference's date selection, desktop grid and adjacent booking panel, with stacked mobile controls. All availability, occupied intervals and own history are rendered by Blade; links and native forms work without feature JavaScript.
- The migration creates nine initial active desks once. Managers can add desks, rename them while retaining stable IDs, and deactivate them through confirmed dashboard controls. Active desk names are checked for duplicates; reusing an inactive desk's name creates a separate identity rather than restoring old history.
- Reservations are independent of shifts. The server permits Sunday–Thursday, actual today through today + 28 calendar days, 09:00–16:30, half-hour boundaries and a minimum of 60 minutes. Today's booking must start strictly after the server's current Israel time. A reservation may end exactly at 16:30.
- Each worker can have at most one active reservation per date. Active intervals on the same desk/date cannot overlap; adjacent intervals can meet at their endpoints. Availability displayed in the browser is advisory, and every write rechecks the current rules under MariaDB locks.
- Before its original start time, a worker can edit the desk/times for their own reservation on its fixed date or explicitly confirm cancellation. Editing cancels the old row and creates the replacement atomically, preserving the prior interval. An unchanged valid edit is idempotent. Workers cannot edit or cancel at or after the start.
- Own active and canceled records remain visible for the selected day. Other workers' busy intervals show times and an own-reservation marker, without loading or displaying private coworker identities. Managers cannot book for themselves or override a worker's reservation.
- Blocking a date uses the existing manager workflow and transaction to cancel both shifts and desk reservations, with actor/time/reason preserved. Reopening restores neither. Worker deactivation immediately cancels future and running desk reservations, retains completed history and revokes account access.
- Desk deactivation immediately cancels future and running reservations and hides the desk from new booking choices. Completed records and prior cancellations retain their stable relationships. Exact end time counts as completed. Refreshing an old link to the deactivated desk safely selects another active desk instead of looping on validation.
- Manager controls show upcoming and running reservation counts and explain cancellation before confirmation. Existing account/date-blocking warnings now include desk reservations. Quick actions link only to implemented sections; the profile link remains in the account dropdown only.

Deferred: CSV and its controls, manager reservation overrides, account/desk reactivation, recurring bookings and deployment acceptance. No Stage 6 tables/routes or report generation were added. All five reference HTML files remain untouched.

## Changed files

| Area | Files |
| --- | --- |
| Definitions/schema/models | New `config/desks.php`, `database/migrations/2026_10_22_000000_create_desk_booking_tables.php`, `app/Models/Desk.php`, `DeskReservation.php` |
| Desk services | New `app/Services/DeskAvailabilityService.php`, `DeskReservationService.php`, `DeskManagementService.php` |
| Existing transaction integration | Updated `app/Services/DateClosureService.php`, `AccountManagementService.php`, `ManagerTransaction.php` |
| Controllers/routes | New `app/Http/Controllers/DeskReservationController.php`, `Admin/DeskController.php`; updated `Admin/UserController.php`, `Admin/BlockedDateController.php`, `routes/web.php` |
| Validation | New `app/Http/Requests/DeskPageRequest.php`, `StoreDeskReservationRequest.php`, `CancelDeskReservationRequest.php`, `StoreDeskRequest.php`, `DeactivateDeskRequest.php`; updated `lang/he/validation.php` |
| Blade/CSS | New `resources/views/worker/desks.blade.php`, `admin/desks/panel.blade.php`, `public/css/desks.css`; updated `components/header.blade.php`, `admin/users/index.blade.php`, `admin/blocks/panel.blade.php`, `admin/events/panel.blade.php`, `admin/receipts/panel.blade.php` |
| Tests | New `tests/Concerns/CreatesDeskReservations.php`, `tests/Feature/DeskReservationTest.php`, `DeskAdministrationTest.php`, `DeskClosureTest.php`, `DeskConcurrencyTest.php`, `DeskPageTest.php`; updated `AccountManagementTest.php`, `CsrfProtectionTest.php` |
| Documentation | `AGENTS.md`, `ROADMAP.md`, `README.md`, `docs/DEPLOYMENT.md`, a progression note in `docs/STAGE4-REPORT.md`, this report |

There is no new feature JavaScript or dependency. Shared `public/js/app.js` still enhances the existing menus, while native links/forms/`<details>` provide desk selection, editing and confirmation. Feature CSS stays scoped and reuses the existing design tokens.

## Follow requests through the code

**Read the desk page:** `worker.desks.index` (`GET /worker/desks?date=YYYY-MM-DD&desk_id=N`) → authentication, active-account, session-hash and worker middleware → `DeskPageRequest` → `DeskReservationController::index()` → `DeskAvailabilityService::forDate()`. The service reads active desks and the selected day's active intervals, then separately reads only the session owner's reservation history. It computes advisory date restrictions/default times from `config/desks.php` and returns view data. `worker/desks.blade.php` renders the grid, selected desk, occupied list and own history. `desks.css` supplies the responsive layout. The grid links use real GET requests rather than rebuilding authoritative state in JavaScript.

**Book:** `worker.desks.store` (`POST /worker/desks/reservations`) → `StoreDeskReservationRequest` → `DeskReservationController::store()` → `DeskReservationService::book()` → `DeskReservation`. Validation accepts `reservation_date`, `desk_id`, `start_time` and `end_time`, rejects supplied ownership/audit fields, and obtains the worker identity from the session. The service locks, rechecks authority/date/time/block/desk/overlap and saves inside one transaction. The redirect opens the selected day and own-history anchor with Hebrew feedback.

**Edit or cancel:** `worker.desks.update` (`PATCH /worker/desks/reservations/{reservation}`) uses the same request, additionally verifies ownership and rejects a date different from the bound record. `DeskReservationController::update()` calls `DeskReservationService::update()`, which locks old/new desk IDs in ascending order, rereads the current record and checks its original start before replacing it. `worker.desks.destroy` (`DELETE` at the same URL) → `CancelDeskReservationRequest` requires ownership and `confirm_cancellation` → `destroy()` → `DeskReservationService::cancel()`. The canceled row remains available. The native confirmation is inside `<details>` and requires a checkbox; no modal script is needed.

**Manage desks:** `admin.desks.store` (`POST /admin/desks`) or `admin.desks.update` (`PATCH /admin/desks/{desk}`) → manager middleware → `StoreDeskRequest` → `Admin/DeskController::store()` / `update()` → `DeskManagementService::create()` / `rename()`. Only the name is accepted; the route owns record identity and the service controls activation. The request scopes flashed old input to the failed inline form. `admin.desks.deactivate` (`PATCH /admin/desks/{desk}/deactivate`) → `DeactivateDeskRequest` requires confirmation → `DeskController::deactivate()` → `DeskManagementService::deactivate()`. `Admin/UserController::index()` reads paginated desks with upcoming/running counts; `admin/desks/panel.blade.php` renders the management controls.

**Close dates or deactivate workers:** the existing routes/controllers still call `DateClosureService::block()` and `AccountManagementService::deactivate()`. Closure now cancels `DeskReservation` rows after canceling shifts within the same manager transaction. Account deactivation calls `DeskReservationService::cancelForDeactivation()` while already holding the worker lock. Both preserve the original interval and first cancellation audit. `unblock()` removes only the block, leaving all canceled records canceled.

## Database integrity and locking

The migration adds only `desks` and `desk_reservations`, both InnoDB. The reservation uses a DATE foreign key to the existing permanent `calendar_dates` table, local TIME columns, restrictive user/desk foreign keys and separate cancellation timestamps/actor/reason/note. It does not delete or translate existing shifts. A generated nullable `active_slot` is 1 for non-canceled rows and NULL otherwise. The unique worker/date/active-slot index allows canceled history and one active reservation per day. MariaDB CHECK constraints enforce office bounds, at least 60 minutes, half-hour minutes and zero seconds. An availability index supports desk/date/active-interval queries.

The overlap rule is `existing.start < requested.end AND existing.end > requested.start`. Unique constraints alone cannot protect arbitrary intervals, so the service performs that check within the locked transaction. Every worker mutation follows **worker row → permanent date → desk IDs in numeric order → reservation rows**. The existing date lock uses a no-op upsert followed by `FOR UPDATE`; it works even when neither a booking nor a blocked-date row existed before. The worker row serializes simultaneous requests for the same worker/date. Desk locks also serialize worker mutations against desk deactivation, including first-ever dates.

All manager writes retain `ManagerTransaction`'s schema-specific advisory gate and active-manager reread. Date closure owns **date → shift/reservation** locks. Desk deactivation deliberately owns **desk → reservation** locks without acquiring dates afterward: taking date locks while holding a desk would reverse the worker order, while enumerating known dates first could miss a concurrent first booking. The exclusive desk row prevents such a booking from escaping cancellation. The shared manager gate serializes closure, worker deactivation and desk deactivation with one another; a manager cancellation never needs to lock another worker's account afterward.

Worker deactivation already holds the worker row before reading its reservation dates. No new booking for that worker can commit unseen. The desk cancellation workflow acquires known dates chronologically, then desk IDs numerically, then reservation rows. Existing shift cancellation runs in the same manager transaction. Clock checks after lock waits enforce today's strict future start, the original edit/cancel cutoff, midnight and completed-at-end behavior. All comparisons use `Asia/Jerusalem`; local date/time choices are distinct from timestamp audit fields.

The one-time migration inserts `עמדה 1` through `עמדה 9`; normal startup/page visits never seed them again. There is no public desk deletion or history cascade. Future deployments run the migration once through the normal migration account.

## Commands and actual verification

```bash
php artisan config:clear
php artisan test --compact
php artisan migrate
vendor/bin/pint --test
composer validate --strict
composer check-platform-reqs
php artisan view:cache
php artisan route:list --except-vendor
git diff --check
```

| Check | Actual result |
| --- | --- |
| Final complete suite | **332 passed, 1892 assertions, no failures/skips**, 42.77 seconds, MariaDB 10.11.14 |
| Earlier complete database run | 331 passed, 1884 assertions, before the stale desk-link review fix/regression |
| Independent-process desk races | Passed: five contenders for one interval, one worker/two desks, adjacent intervals, first-ever blocked-date races, desk deactivation versus its first booking, worker deactivation versus booking, and simultaneous manager closure/worker/desk deactivation |
| Business/security/schema coverage | Passed: office bounds, half-hour/minimum rules, exact start/end cutoffs, post-lock time rechecks, date horizon/weekends, ownership/role/active account/CSRF, overlapping versus adjacent intervals, one active daily row, canceled history, MariaDB CHECK constraints, private busy intervals and escaped labels |
| Local migration | Applied successfully; nine initial desks inserted, existing application records preserved |
| Pint, Composer validation/platform | Passed on PHP 8.3.6 |
| Blade cache and routes | Passed; 28 intended application routes, including seven Stage 5 routes and no CSV routes |
| Unsaved UI fixtures | 11 checks passed for grid/native forms, history, read-only states, manager controls and role navigation |
| Browser layouts | Firefox desktop 1440px, worker 390px/320px and manager 390px inspected; native confirmation warnings checked |
| Local HTTP smoke check | `/login` returned HTTP 200 after starting the local development server and applying the migration |
| Reference/layout integrity | All five HTML references and supplied logo unchanged; public logo matches original; calendar receipts-first include order preserved; whitespace check passed |

The initial test attempt could not connect to MariaDB and completed zero assertions. The background database started by the tool did not remain alive; a foreground local MariaDB tool session resolved the runtime issue without replacing the persistent data or changing credentials. Both complete database runs above then passed. Independent review found the stale selected-desk GET case; the fix and regression are included in the final result. Temporary previews used unsaved models and wrote no application accounts or reservations.

The test harness guards a separate `_test` MariaDB schema before migrations run. Concurrency tests use committed fixtures and independent connections/processes, not SQLite or browser availability hints. No operator credentials, private environment files, uploaded images or calendar layout order were changed.

## Manual review and remaining limits

Follow `README.md` under “Review Stage 5”: reserve/edit/cancel as a worker; compare two independently signed-in workers on an overlapping slot and adjacent slots; add/rename/deactivate as manager; and exercise combined blocking/reopening and worker deactivation using disposable data. Check mobile RTL, keyboard access, native forms without JS and canceled history. Automated clock tests cover the running/start/end boundaries without changing the real server time.

Setup commands remain `bash scripts/local-database.sh start`, `php artisan migrate`, and `php artisan serve --host=127.0.0.1 --port=8000`. Other installations must migrate before using the new routes. Preserve both new tables, generated active-slot columns, constraints and all canceled history in confidential off-device backups. There is no new upload directory, build step, queue or scheduled task.

No failing automated check remains. Owner acceptance and actual Raspberry Pi/Apache/HTTPS/backup-restore validation remain pending. Stop after Stage 5; wait for explicit review and authorization before starting Stage 6.

## Follow-up: disabled dates also disable desk cards

The worker desk grid now renders native disabled cards whenever `canBookDate` is false, including manager-blocked dates. Disabled cards have an unavailable label, muted styling, no selection links and no available/selected cues. Date navigation and reservation history remain readable. Reopening a date enables desk choices again without restoring canceled records. The existing transactional blocking and booking rejection remain unchanged; no migration is needed.

Updated `resources/views/worker/desks.blade.php`, `public/css/desks.css`, the existing assertions in `tests/Feature/DeskPageTest.php`, and the documented rule/review steps in `AGENTS.md`, `ROADMAP.md` and `README.md`. The focused MariaDB run of `DeskPageTest|DeskClosureTest` passed **19 tests and 148 assertions**, covering blocked cards, read-only dates, reopening, cancellation history and server rejection. Blade compilation, Pint and whitespace checks passed. For manual review, block a disposable date, open it on the worker desks page and confirm every card is disabled; reopen it and refresh to check that selection returns while canceled history stays canceled.
