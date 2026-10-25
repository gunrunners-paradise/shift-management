# Stage 7 — Local audit and deployment rehearsal — 2026-10-25

## Authorization and delivery boundary

The user explicitly authorized Stage 7 and confirmed: **complete local checks now; Raspberry Pi verification later**. This report covers the available development host and isolated synthetic test environments. It does not approve an office pilot or claim results on the Pi's 8 GB RAM / 32 GB storage. Final device verification and owner acceptance remain pending.

The existing policies remain intact: five shift periods, administration-only managers, calendar → receipts → all other information, current-month-only worker receipt changes, independently selected CSV receipt month, retained history on ordinary deactivation and explicit deletion exceptions. No new feature page, production route, table, migration, dependency or scheduled task was added.

## Finding and correction

The audit found one security defect in an already-running request. Middleware checks the account and login version before a controller runs, but a request can then wait for a database lock. If a manager deactivated and reactivated the account during that wait, several services previously checked only the account's current active flag and role. The old request could therefore resume with authority belonging to its revoked login.

The existing locked authorization checks now also compare the actor's `session_version` with the current database value. This applies to manager operations, shift changes, desk booking/edit/cancel, receipts, profile updates and password changes. A stale request receives an authorization error; a fresh login can perform the same valid action. The existing CSV service already made this comparison. Lock ordering, cancellation history, reactivation behavior and last-manager protection remain unchanged.

Browser inspection found a second defect at 320 px: the desk date field was 133.86 px wide, but Chromium's native date input needed 159 px and overlapped the neighboring submit button by 13.14 px. The existing breakpoint at 370 px now gives the date field and button full-width separate rows. At 320 px, both now measure 264 px with a 12 px gap between rows; time controls still fit their fields. Wider layouts and the shared design tokens remain intact.

The source audit found no remaining production localStorage/demo pathways or need to redesign the approved pages. Rendered-page tests now check connected labels, unique element IDs, valid ARIA references and real in-page navigation targets across both roles.

## Changed files and learning path

| File | Purpose |
|---|---|
| `app/Services/ManagerTransaction.php` | `run()` rejects an old login under the current manager row lock before any administrative write. |
| `app/Services/ShiftSchedulingService.php` | `withWorkerDate()` checks the login version before date serialization and shift changes. |
| `app/Services/DeskReservationService.php` | `withWorkerDate()` rejects stale authority before desk/date booking operations. |
| `app/Services/MonthlyReceiptService.php` | `set()` checks the login version under the worker lock. |
| `app/Services/ProfileService.php` | `lockActiveUser()` protects both personal details/photo changes and password changes. |
| `public/css/desks.css` | Stacks the date field and submit button at the existing narrow-screen breakpoint to prevent native-input overlap. |
| `tests/Feature/Stage7WorkflowTest.php` | Two native HTTP workflows connect account creation, holidays, bookings, receipts, CSV, closure/reopening and deactivation/reactivation/deletion; six cases cover revoked in-flight requests. |
| `tests/Feature/Stage7InterfaceTest.php` | Six rendered-page cases verify Hebrew RTL, unique IDs and label/ARIA/navigation destinations with populated records. |
| `tests/Feature/Stage7SecurityTest.php` | Checks restrictive browser/JSON headers and generic Hebrew error rendering with debug disabled. |
| `tests/Feature/AccountReactivationTest.php` | Refreshes the actor before explicitly fresh post-reactivation service calls, matching a new login. |
| `docs/DEPLOYMENT.md`, `docs/deployment/apache-vhost.conf` | Repeatable deployment, HTTPS/public-root restrictions, permissions, recovery, resource checks and deferred device evidence. |
| `README.md`, `ROADMAP.md`, `docs/STAGE6-REPORT.md`, `docs/EXTRA-FEATURES-REPORT.md` | Current review status, Stage 7 authorization and links to verification and operator instructions. |

For a worker selection, follow named route `worker.shifts.store` (`POST /worker/shifts`) → authentication/active-account/worker middleware → `StoreShiftRequest` → `ShiftCalendarController::store()` → `ShiftSchedulingService::select()` / `withWorkerDate()`. The service locks the current account, verifies its login version, acquires the permanent date lock and updates `Shift` records transactionally. The redirect renders `worker/home.blade.php` through the shared layout and `components/shift-slot.blade.php`. `public/js/calendar.js` enhances the native forms with the selection dialog; PHP still renders the five-row calendar. `public/js/today.js` refreshes only today's supplemental information.

For administrative changes, the named admin route and its FormRequest reach the relevant controller/service and `ManagerTransaction::run()`. That shared manager gate now checks the login version under the same actor lock used to protect authority. Existing Blade panels and native CSRF-protected forms still render and submit the result. No new UI control or JavaScript is required for this correction.

## Automated and source checks

| Check | Actual result |
|---|---|
| Full MariaDB suite: `php artisan test --compact --stop-on-failure --stop-on-error` | **500 passed, 3758 assertions, 76.73 seconds; no failures or skips.** Includes the existing independent-process concurrency scenarios. |
| Focused Stage 7 suite: `--filter=Stage7` | **18 passed, 878 assertions, 1.73 seconds.** |
| `vendor/bin/pint --test` | Passed across the application. |
| `php artisan view:cache` | All Blade templates compiled. |
| `composer validate --strict --no-check-publish` | Passed. |
| `composer check-platform-reqs` | Passed for installed development dependencies. |
| `composer audit --locked --no-interaction` | No security vulnerability advisories found on 2026-10-25; dependency versions were not changed. |
| Isolated production installation | `composer install --no-dev --optimize-autoloader --no-interaction --no-progress`, `composer check-platform-reqs --no-dev` and `php artisan optimize` passed in the restored copy. |
| Production demo/source scan | No localStorage, prototype `.html` navigation or demo/stub text found in `app/`, `resources/`, `public/` or `routes/`. |
| Reference integrity | All five HTML references and both copies of the supplied logo remain unchanged. |
| Tracked private files | Only safe environment examples and storage `.gitignore` placeholders are tracked; no private environment, upload, local database or backup was added. |

The first full run stopped at a mistake in the new workflow fixture: it omitted the existing deactivation acknowledgement. That run had 400 passes, one failure and 99 unexecuted cases. The fixture now submits `confirm_deactivation=1`; the focused and final full runs above passed. No failed application assertion remains from that attempt.

Tests used the guarded MariaDB `_test` database. The operator's live accounts, database records, uploads, `.env` and application key were not modified. Browser tooling was downloaded temporarily under `/tmp`; no browser or JavaScript package was added to project dependencies.

## Actual local backup and restore rehearsal

Two new disposable schemas ending in `_test` and two temporary application directories were created. They used generated synthetic keys, credentials, users and image data, with no copy of real office data or private environment configuration. The restore ran at a separate URL and used a distinct session cookie.

The source fixture contained three accounts, nine desks, nine shifts, four desk reservations, two receipt records, two calendar events, one blocked date and nine permanent calendar-date lock records. It included all five shift categories, active and canceled records, an inactive worker, actual-today birthdays/announcements and a private PNG. The ten table snapshots also included migrations and sessions.

The actual procedure migrated the source schema, captured row/schema hashes, ran `mariadb-dump --single-transaction --quick --skip-lock-tables --skip-triggers`, archived the synthetic environment and upload files, verified SHA-256 manifests, imported into the separate restore schema and restored the matching files/key. All ten tables had identical row and schema hashes before clearing the one copied session. The application then required a fresh login.

| Recovery check | Actual result |
|---|---|
| SQL dump | 18,141 bytes; 0.040 seconds. |
| Synthetic environment/upload archive | 1,079 bytes; manifest verified. |
| Import plus integrity verification | 0.511 seconds; setup/rehearsal total 4.832 seconds. |
| Stable identities, dates and history | All source/restore row hashes matched, including cancellations and permanent date locks. |
| Schema and engines | All table schema hashes matched; all tables used InnoDB. |
| Database rejection probes | Three unique constraints, four CHECK constraints and two foreign keys rejected invalid writes/deletes. |
| Generated active slots | Active records yielded `1`; canceled records yielded `NULL` for shifts and desk reservations. |
| Private image/key | Restored 177-byte PNG hash matched; original synthetic APP_KEY preserved. |
| Production bootstrap | Debug disabled; config, route, event and view caches built successfully. |
| Runtime database privileges | Exactly SELECT, INSERT, UPDATE and DELETE on the restored schema. All four operations worked in a rolled-back transaction; CREATE TABLE was rejected with MariaDB error 1142. |

These small synthetic timings demonstrate recovery mechanics on this host. They do not estimate recovery time for real office history or substitute for an off-device backup. The guide specifies the private backup manifest, original-key preservation, upload checksums, restricted accounts and isolated recovery steps for the operator.

## Apache and HTTPS checks

The supplied virtual-host example was adapted into a temporary configuration bound only to loopback ports 8187/8447, serving the restored application's `public/`. The system Apache service/configuration was not changed. `apache2 -t -f <temporary-config>` reported **Syntax OK**. The first sandboxed syntax check could not allocate a socket; the check with local socket access passed.

HTTP `/login` redirected with 301 to the separate HTTPS endpoint. The actual application session cookie had Secure, HttpOnly, SameSite=Lax and an expiry about 30 days away; no cookie values were recorded. HTTPS requests returned 200 for `/login`, `/up`, CSS, JavaScript and the supplied logo. Requests for `.env`/`.env.migrate`, repository files, all five reference pages and raw storage paths returned 403/404 without content. Harmless plain-text unexpected PHP, nested `index.php` and dotfile probes were denied with 403. An explicit temporary alias to private storage was also denied. The example authorizes PHP only when its mapped path equals the root `public/index.php`.

The temporary certificate was self-signed solely for this loopback test. Browser verification permits that test certificate; office DNS, organization-trusted certificates, renewal, firewall access and actual Pi permissions still require operator verification.

## Browser and resource evidence

The browser audit compared all five exact references at desktop/mobile sizes and exercised the restored production copy at 1440, 850, 700, 375 and 320 px. The initial live pass covered 30 page/viewport combinations (the five pages plus the manager's profile) with 263 successful checks and no failed checks, JavaScript/CSP errors or document-level horizontal overflow. Calendar/table scrolling remains local to their containers. Visual inspection then found the internal native-date overlap described above, which a document-overflow check alone would miss. After the CSS correction, all date/time controls fit without adjacent overlap at all five widths.

Thirteen additional browser behavior checks passed: blocked dates disabled all nine desk cards, busy intervals were server-rendered, native shift/receipt/logout forms worked with JavaScript disabled, and CSV downloads for two different receipt months preserved the same workday columns while changing only receipt state. The polling check advanced the browser clock through 30 seconds, simulated a hidden document to verify no new requests, then simulated visibility to verify an immediate refresh. This tests the lifecycle contract; it is not a physical mobile device/background-power test. Five focused narrow-control checks passed after the CSS fix. The full PHP suite above preceded that final CSS-only adjustment; no further PHP change followed it.

During the narrow-control review, the development MariaDB process stopped and its socket disappeared. The rehearsal showed the safe generic Hebrew 500 page without database diagnostics. Restarting the same persistent instance restored HTTP 200 without resetting data; the remaining browser behavior checks then passed. This runtime interruption did not require an application-code change.

Temporary screenshots and sanitized JSON evidence were captured outside Git under `/tmp/shift-stage7-ui/`; recovery/performance evidence used a separate private `/tmp/shift-stage7-rehearsal-*` directory. Only synthetic accounts appear in those artifacts. They are transient local review evidence, not office backups.

After verification, the temporary Apache instance was stopped. Both disposable rehearsal schemas and their temporary database account were removed, and metadata checks confirmed the existing local and main test schemas remained present. Temporary application copies, synthetic backup files, test TLS key and browser downloads were removed. Screenshots and sanitized recovery/resource/privilege summaries remain together under `/tmp/shift-stage7-ui/`. No deployment service or scheduled process was installed.

A separate synthetic source-schema probe added 100 workers and 26,100 shifts across 261 Sunday–Thursday dates, from 2025-10-26 through 2026-10-25. One fresh PHP process with a 128 MiB memory limit generated a 318,581-byte report containing 102 worker rows and 26,107 scheduled days, including the two original retained workers. All four-column rows and totals matched MariaDB. Generation took **331 ms**, with **24 MiB peak allocated PHP memory** versus 22 MiB before generation. The measured shift table/index allocation was about 5 MiB.

This is one local sample, potentially using a warm database cache. Its output stayed below the 2 MiB temporary-stream threshold, so it did not measure disk spill or prove a maximum safe range. PDO buffers selected rows; total memory is not limited by the output-stream threshold. Repeat representative workloads on the Pi before choosing its process/concurrency limits.

The available host is x86_64 with PHP 8.3.6, Apache 2.4.58, MariaDB 10.11.14 and Chromium 156.0.8078.4. Its reported memory is about 15.3 GiB, so it is not the target 8 GB Raspberry Pi. The deployment guide includes device RAM/disk/inode measurements, bounded log retention checks, avatar limits and a repeatable workload measurement procedure.

The local Apache rotation configuration matches `/var/log/apache2/*.log`, including the example site's log names, and specifies daily rotation with 14 retained compressed files. It was inspected without rotating logs. The application's documented daily channel retains seven days; neither setting imposes a per-day byte cap. Actual Pi rotation, journal retention and free-space thresholds still need review on that device.

## Owner review and remaining device work

1. In a review environment, sign in as each role. Check the desktop/mobile header, profile-only account link, five calendar periods and calendar → receipts → announcements/birthdays/coworkers order.
2. Use disposable accounts to create a manager/worker, reserve a shift and a desk, block/reopen the day and verify canceled records do not return. Repeat account deactivation/reactivation and confirm a fresh login is required.
3. Download a CSV with a selected receipt month. Check Hebrew headers, chronological dates within one cell, canceled shifts excluded, retained zero-day workers and `קבלות` values of `יש`/`אין` in the intended spreadsheet software.
4. Follow [DEPLOYMENT.md](DEPLOYMENT.md) on the Pi when available. Verify trusted HTTPS, runtime/migration/backup privileges, service restart, real off-device backup/restore, private uploads and office-network role flows.
5. Record Pi RAM/storage/log growth and representative concurrency/CSV timings before the owner approves the office pilot. Local automated results alone do not grant that approval.

Raspberry Pi installation, office-trusted HTTPS, device resource limits, off-device backup media, reboot/recovery arrangements and final pilot sign-off remain **pending at the user's request**. No new stage is automatically opened.
