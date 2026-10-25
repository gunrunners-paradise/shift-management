# ROADMAP.md — Staged delivery of מערכת נוכחות

> Companion to `AGENTS.md`. **Build one approved feature stage at a time.** The five HTML files are references for the *eventual* website; they are not instructions to implement the entire UI in the first stage.

## 0. Stage authorization and review protocol

**Current status at creation:** no implementation stage has been authorized in this document. **Stage 1 is the next proposed stage**. Wait for an explicit user instruction such as **“Implement Stage 1”** before coding.

Current authorization and review notes are maintained at the end of this document; the creation-time statement above is historical.

For every stage:

1. Read `AGENTS.md`, this roadmap, the **five reference HTML prototypes**, and the relevant existing Laravel code and tests.
2. State a small plan limited to the stage, including migrations, controllers, Blade, CSS/JS and tests likely to change.
3. Implement only that scope; reuse working components and keep future feature controls hidden/inactive **without creating fake data or placeholder buttons that appear functional**. Do not create future DB tables or routes ahead of the stage.
4. Execute relevant automated tests and manual checks (including mobile RTL where appropriate); report exactly what was actually tested.
5. Summarize changed files, implementation choices, test results, known limitations, a **brief code-reading guide** (the route/controller/model/view path for an example request), and the shortest practical way for the user to verify the feature on their machine or Raspberry Pi.
6. **STOP AND WAIT** for the user's explicit acceptance and approval to begin the next stage. Do not infer approval from silence or from a successful test run.

Stage checkboxes may be marked complete **only after user approval**. The agent may update progress notes, but must never mark an unfinished/unauthorized stage complete or move itself forward.

### Feature stages

| Stage | Goal | Status |
|---|---|---|
| 1 | Laravel foundation, login, accounts and role-specific layouts | [ ] Delivered; progression to Stage 2 authorized on 2026-10-20 |
| 2 | **Five-period** worker shift calendar and manager blocked dates | [ ] Delivered; progression to Stage 3 authorized on 2026-10-20 |
| 3 | Personal profiles, birthdays and today's coworkers | [ ] Delivered; progression to Stage 4 authorized on 2026-10-20 |
| 4 | Calendar events, holidays and monthly receipts | [ ] Delivered; progression to Stage 5 authorized on 2026-10-22 |
| 5 | Desk selection, reservations and manager desk administration | [ ] Delivered; progression to Stage 6 authorized on 2026-10-22 |
| 6 | On-demand CSV download (scheduled workdays) | [ ] Delivered; progression to Stage 7 authorized on 2026-10-25 |
| 7 | End-to-end review, security and Raspberry Pi deployment | [ ] Local work delivered for review on 2026-10-25; Raspberry Pi verification and final approval pending |

Security, validation, test coverage and simple Raspberry Pi compatibility are **ongoing obligations**, not tasks to postpone to Stage 7. The final deployment/hardening stage is not a substitute for testing earlier stages.

### Source filenames in the repository root

`AGENTS.md`, `ROADMAP.md`, `admin-management.html`, `login-prototype.html`, `shift-calendar.html`, `worker-desks-prototype.html`, and `worker-profile-prototype.html` are peers in the project root. Read the **exact** names. `shift-calendar.html` should be the updated five-row visual reference; if it is a stale three-row version, follow the latest five-period business rules and ask the user to replace the prototype when practical.

At each stage, write straightforward, **human-readable, professionally commented code**. In the completion report, trace at least one key request through route → controller → service/model → DB → Blade view → optional JavaScript so the owner can **study** the code. Prefer clear simple Laravel conventions; no unnecessary abstractions.

## Stage 1 — Foundation, authentication and minimal account management

**Goal:** a real Laravel MVC web app with a working login and role-specific navigation, usable against MariaDB. No calendar, receipt, event, desk, or CSV feature is implemented yet.

**Scope:**

- Initialize or adapt the supported Laravel version; configure MVC, Eloquent, Blade, MariaDB/InnoDB, Hebrew/RTL, `Asia/Jerusalem`, Apache `public/` compatibility, `.env.example` and `.gitignore`.
- Establish common typography/colors/buttons/cards/form/dropdown CSS and **two** Blade layouts: guest login (minimal bar; logo in login card) and authenticated layout (circular logo at RTL right, hamburger immediately left, user menu at opposite side).
- Implement username/password login, secure session logout, invalid-credentials handling, rate limiting, active-account checks, CSRF and guest/auth/role middleware. **Workers and managers cannot enter one another's role-exclusive pages.**
- Bootstrap the first manager **securely via a documented one-time interactive CLI/setup command**: ask the operator to choose a username and a hidden, confirmed password; do not use default `admin/admin` credentials. After account creation, **show the username and configured login URL** and explain how to log in; the operator knows their own chosen password, which must never appear in terminal output, logs, or source files. If a generated temporary password is used, reveal it securely once and force change at first login. Re-running the command must not duplicate the account or overwrite existing credentials. Manager dashboard starts with the **account-management section only**; create worker and manager accounts, list them, support safe deactivation (and protect against losing last active manager). Use first/last name, username, password, gender, birthday, role; photo uploads can be completed in Stage 3.
- Provide basic profile-menu navigation only for features that already exist. Profile editing is Stage 3; don't link to a dead route. Initial worker landing route can display a simple truthful "shift calendar coming in Stage 2" page, **not** a mock working calendar. Manager lands on admin management. Manager cannot use worker scheduling features.
- Keep the five HTML files untouched; incorporate their design language into the new layouts rather than shipping prototype JS/localStorage as authentication.

**Acceptance and tests:** login/logout, role-based redirects, disabled-user login refusal, username uniqueness, worker self-signup forbidden, manager can create manager, worker cannot call manager actions, CSRF, password hashes, mobile header. Verify that first-manager bootstrap requires no known default credentials, prompts securely for a chosen password, displays the username and login URL, supports successful login, and does not create duplicate managers or reset the password if re-run. Document the CLI setup command, local MariaDB setup and the RPi's `.env` configuration.

**Stop gate:** user has the initial manager username and login URL, knows the password chosen during setup, can sign in as that manager, create a worker, and verify role behavior; user approves Stage 1 before Stage 2.

## Stage 2 — Worker shift calendar and blocked dates

**Goal:** workers schedule their own shifts; managers can block/reopen days safely.

**Scope:**

- `shifts`, `blocked_dates`, and required cancellation-history/audit mechanism. Preserve history without defeating the **one active shift per worker/date** rule.
- Replace the Stage 1 worker placeholder with the real **Blade-rendered** Sunday–Thursday weekly calendar. Previous/next/current-week navigation, date labels, today's indicator, selection dialog and mobile layout. **Exactly five period rows** as specified by `AGENTS.md`: `09_13`, `10_14`, `11_15`, `12_16` and `full`. Use the layout and theme from **`shift-calendar.html`**; if an old copy has only three rows, the **approved five-row rule overrides it**. Keep a compact, readable grid across desktop/mobile.
- Fixed shift categories: `09_13` (09:00–13:00), `10_14` (10:00–14:00), `11_15` (11:00–15:00), `12_16` (12:00–16:00), `full` (09:00–16:30). **No old morning/afternoon category.** Define the five code/label/start/end/duration records **once** in a readable config or enum, referenced by validation, server-rendered calendar and coworker labels. Prefer normal string-backed codes, so future schedule changes do not require altering a DB enum.
- Select office shift (`משמרת חממה`) or no shift (`בלי משמרת`); switching periods **replaces** the one active selection per day. White/selected purple/other four pale pink/blocked **all five strong red** states; other available period boxes remain selectable. Past/too-far dates are read-only. Preserve cancellation history.
- Worker date validation: **today through today+28 calendar days**, Sunday–Thursday; changing today's shifts is permitted all day even after shift start. No cap on worker capacity. Show only logged-in worker's shifts in main calendar.
- Add actual manager **blocked-date** section and quick action to admin page. Managers can block **today or future**, optional reason; confirm existing shifts will be canceled and log cancellations. Block prevents new selections. On unblock, canceled shifts remain canceled and workers must rebook. Blocked dates may be beyond the worker's 28-day window.
- Structure closure logic so Stage 5 can add desk cancellation, without creating unused desk tables now.
- Apply worker deactivation to future shifts and today's upcoming or in-progress shift. The user confirmed cancellation of in-progress shifts on 2026-10-20. Preserve earlier dates and completed shifts, including today's shift at or after its configured end time.

**Acceptance and tests:** only the five approved codes accepted; all five time labels render correctly in Blade on desktop/mobile; obsolete `morning`/`afternoon` inputs rejected; worker cannot view/modify another worker's shifts; manager cannot self-schedule; one active selection per day even though four-hour periods overlap; replacing/clearing works and is recorded in history; user cannot book Fri/Sat/past/+29 days; today's shift editable even after start; blocked-day mutations rejected; cancellation records retained; reopen does not resurrect bookings; manager cannot block past days; concurrently blocking a date and creating a shift cannot leave an active booking on a blocked date. Test against MariaDB where DB-specific integrity is involved.

**Stop gate:** worker and manager exercise this scheduling/closure workflow, review mobile display, and approve Stage 2.

## Stage 3 — Profile page, birthdays and coworkers

**Goal:** users can maintain their own profiles; the worker calendar displays accurate today's-person information.

**Scope:**

- Adapt `worker-profile-prototype.html`: own profile view, name, gender, birthday, uploaded image, read-only username, distinct current/new/confirm password form with current-password verification. Display manager title based on gender; managers access **profile**, not worker bookings. Secure storage/validation of uploaded files.
- Keep one shared profile dropdown in authenticated pages, with a working `הפרופיל שלי` route and logout. The profile link appears only in the avatar/account dropdown, never in the hamburger menu.
- Birthday notice **below** the worker calendar, as confirmed by the user on 2026-10-20. Show only real active worker birthdays matching today's day/month, with multiple names possible and no birth year/age. February 29 birthdays appear on **February 28 in non-leap years**, and February 29 in leap years, as confirmed by the user. Never show permanently hardcoded birthdays.
- Separate **`מי עובד איתי היום?`** list **beneath** calendar. Initial Blade list of workers scheduled for **actual today** (including viewer when registered), with name/photo/**one of the five approved shift period labels**; exclude managers and canceled shifts. A small authenticated JSON route can be polled ~30 seconds, paused in background and immediately refreshed on focus or one's own scheduling change. No WebSockets, no full calendar DOM re-generation.
- All extra calendar information belongs **below the grid**. The confirmed order is **calendar, receipts box, then announcements/birthdays/coworkers**, once each feature is authorized. Keep this order on desktop and mobile and preserve the five existing period rows and scheduling behavior.

**Acceptance and tests:** worker cannot edit others' accounts, manager cannot edit via worker self-service, password updated only with correct current password, safe photo upload, shared header consistency; birthday appears only on matching day; multiple birthdays; roster reflects concurrent workers correctly and excludes canceled shifts/managers; local initial render works without JS.

**Stop gate:** user checks profile forms and roster/birthday layout on desktop/mobile, then approves Stage 3.

## Stage 4 — Manager events, holidays and monthly receipts

**Goal:** informative calendar announcements and a simple two-state receipts report.

**Scope:**

- Manager's **calendar events** section (title/date/optional description, list/edit/remove as needed); managers **manually create and label holidays**. Initially single-day events as shown by prototype; ask before multi-day behavior. Event or holiday **does not** block a workday; manager date blocking remains an independent action.
- Worker calendar **holiday/event announcement** below the receipts box, only when today's recorded event/holiday matches; birthdays and coworkers also follow the receipts box. No permanently-on sample `חג לדוגמה היום!`.
- Monthly worker boolean **`יש לי קבלות החודש`**, placed **immediately below the calendar and above all other supplemental information**, as confirmed on 2026-10-20. Checked=yes, unchecked=no, missing DB row=no; exactly one worker/month record, month tied to actual calendar month (not the selected week). Managers cannot tick it as workers.
- Manager `מעקב קבלות חודשי` section: month picker, worker list, true/false states and count of workers with receipts; admin quick action scrolls there. Confirmed on 2026-10-20: workers may update **the actual current month only**. Reject past/future writes and stale forms crossing a month boundary; managers may view selected months.

**Acceptance and tests:** event/holiday alone never blocks shifts/desks; only today's events displayed in worker announcement; receipt defaults false with no third state; one worker cannot write another's month, previous months remain separate; manager can view chosen month; only authorized manager can maintain events/holidays.

**Stop gate:** user reviews announcements and receipts across two simulated months; approves Stage 4.

## Stage 5 — Workstation reservations and desk administration

**Goal:** a fully functional worker desk booking page and manager desk controls, with correct multi-user concurrency.

**Scope:**

- Implement `desks` and `desk_reservations` and canceled-history/status tracking; create **nine initial active desks** with stable IDs; do not reseed duplicates.
- Worker page from `worker-desks-prototype.html`: date navigation, grid of available/partly occupied desks, selected desk/booking panel, busy intervals, worker's own bookings, and cancellation confirmation. Match desktop/mobile RTL layout and existing shared header.
- Reservations are **independent of shifts**. Sunday–Thursday; dates today through +28 days; office window **09:00–16:30**. Both endpoints `:00`/`:30`, **minimum 60 minutes**, positive same-day interval, no overnight. Today's new reservation must start **strictly in the future**. A worker may have **only one active reservation on any date**. Workers can edit/cancel only **before** the booking starts.
- Validate no overlap for any same desk/day. Lock consistently in MariaDB transaction to prevent (a) two users racing for same desk/time and (b) one user racing to get two desks. Permit back-to-back times. Distinguish canceled bookings from active.
- Add manager desk management section and quick action: add and rename desks. Latest confirmed follow-up (2026-10-22) replaces desk deactivation with **permanent deletion of the desk and all its reservations**, including past, completed, canceled, upcoming and in-progress records. Require an acknowledgement and clear warning without typed credentials; support existing active and legacy inactive desks. Delete atomically under the manager/desk locks while preserving unrelated records and date-lock identities. Do not automatically purge existing desks.
- Extend manager blocked dates so they cancel/reject desk reservations **as well as shifts**, with one consistent action. Deactivation of a worker now also cancels their future and **in-progress** desk reservations immediately. Unblocking never revives canceled desk bookings.
- On dates unavailable for booking, desk cards are visibly disabled with explicit text and cannot be selected. Reopening enables desk choices without restoring canceled reservations.

**Acceptance and tests:** office hours and 30-minute grid enforced server-side; 30-minute reservation rejected (minimum one hour); 09:00–10:00/15:30–16:30 accepted as applicable; 16:00–17:00 rejected; new booking starting before current time rejected; disjoint second booking on same date rejected; overlaps rejected; back-to-back different workers accepted; concurrent booking stress against MariaDB, including a block-date race; user cannot cancel after start; closure/account deactivation preserve cancellation history; permanent desk removal deletes only that desk and all its reservations, safely racing booking/edit/cancel requests; manager cannot book desks.

**Stop gate:** demonstrate two worker accounts attempting the same slot; show manager blocking/deactivation scenarios and history; user approves Stage 5.

## Stage 6 — On-demand workday CSV download

**Goal:** managers can **manually request** an Excel-friendly Hebrew CSV of scheduled workdays for a selected date range. Reports are **never automatically or periodically generated or delivered**.

**Scope:**

- Admin dashboard report section and quick action. An authorized manager chooses start/end dates and a receipt month, then **clicks the Download CSV button**. That action alone triggers the MariaDB-backed query and a safe download/stream (not prototype-localStorage).
- **No scheduled or recurring exports, cron tasks, background jobs, emails, automatic generation, or precomputed reports.** Merely opening the admin page never generates a CSV.
- **Scheduled shifts, not verified attendance**. One day per worker/date, whether their selected period is a **4-hour shift or the 7h30m full shift**; only non-canceled records; chronological date list, comma+space separated within a single quoted cell.
- Column order/names: `שם העובד`, `מספר ימי עבודה`, `תאריכי עבודה`, `קבלות`. Correct UTF-8 BOM for Excel, cell escaping, spreadsheet formula-injection mitigation for user names, and stable handling of Hebrew/dates.
- Confirmed follow-up (2026-10-25): a required `חודש קבלות` selector defaults to the actual current Israel month and is independent of the workday range and the dashboard receipt-list picker. Accept any valid `YYYY-MM` month for reading. The fourth column is `יש` for a checked receipt record in that month, otherwise `אין` for an unchecked/missing record. This includes inactive and zero-day workers and does not change worker current-month-only editing.
- Include every retained worker account, active or inactive, including **zero-day workers** with count 0 and an empty date cell (confirmed on 2026-10-22). Keep historical work unless the account and its history are permanently deleted. Managers never appear as worker rows. Start/end dates are inclusive and may be **no later than actual Israel today**; reject future values (confirmed on 2026-10-22).

**Acceptance and tests:** a CSV is generated only after an authorized manager explicitly requests it; dashboard visits generate nothing, and there are no automatic/scheduled reports. A full shift and a 09:00–13:00 shift on different dates count two days, not hours; all five categories count one day each; cancellation never counts; range inclusive; dates are sorted; commas in date cell do not create extra columns; Hebrew readable in Excel; unauthorized worker denied CSV access; historical data preserved. Receipt values match the selected month, missing/false records produce `אין`, true records produce `יש`, and receipt joins do not duplicate workdays. Validate month input and its independence from workday dates and the dashboard receipt-list filter.

**Stop gate:** user downloads and inspects a CSV in preferred spreadsheet software; approves Stage 6.

## Stage 7 — Cross-feature audit and Raspberry Pi 4 deployment

**Goal:** review the finished site end-to-end and document a safe, repeatable deployment path for the office pilot and later server migration.

**Confirmed verification scope (2026-10-25):** complete the local audit, browser checks and synthetic deployment/backup/restore rehearsal now. The user deferred Raspberry Pi verification until the device is available. Keep device evidence and office-pilot approval pending.

**Scope:**

- Cross-feature tests: role separation, managers creating managers, account deactivation canceling reservations, reopening blocked dates not restoring data, false receipts default, holidays not closing office, concurrent desk booking protection, CSV excluding cancellations.
- Visually compare all five pages against their **exactly named** references; verify that the worker calendar has the **five latest shift choices even if a stale prototype still shows three**. Correct shared header/buttons/colors/spacing/RTL/mobile behavior without introducing conflicting CSS or losing any approved features. Remove leftover mock data/stub buttons/localStorage demo pathways.
- Raspberry Pi setup guide: supported PHP/Laravel requirements, Composer, Apache vhost and `public/` document root, MariaDB InnoDB/database permissions, `.env` secrets, migrations, storage/public files, folder permissions, prod config/cache, session/auth hardening, secure HTTPS plan and backup/restore verification.
- Test on realistic resources (Pi 4 / 8 GB RAM / 32 GB disk) and document storage usage, photo size limits, DB backups to external/off-device media, log rotation, and simple recovery steps. Demonstrate migration to another server via environment config + DB/storage backups, not embedded local paths.

**Acceptance and tests:** end-to-end functionality on deployment environment; `APP_DEBUG=false`; authenticated-only data; no plaintext credentials or seeded default passwords; successful backup **and restore** rehearsal; valid Apache `public/` setup; clear deploy/runbook with real commands validated in the available environment (mark unverified steps honestly).

**Final approval gate:** user signs off on office pilot readiness. Do not claim production-ready merely because local tests pass.

## Decisions deliberately left for their relevant stages

Do **not** let these open topics block Stage 1; do **not** invent answers when implementing them:

- Stage 2: cancellation of an in-progress shift on worker deactivation was confirmed on 2026-10-20. Manager editing an individual worker's shift remains unauthorized.
- Stage 3: February 29 birthdays use February 28 in non-leap years (confirmed). Manager editing of other users' profiles remains unauthorized.
- Stage 4: current-month-only worker receipt editing is confirmed. Events remain single-date; multi-day support requires separate approval.
- Stage 5 follow-up: permanent desk deletion replaces deactivation and removes every linked reservation/history record (confirmed on 2026-10-22). Additional manual manager reservation overrides are not authorized.
- Stage 6: include zero-day retained workers; allow report dates only through actual Israel today. Both choices were confirmed on 2026-10-22. The fourth `קבלות` column uses an independently selected receipt month, confirmed on 2026-10-25.
- No email/SMS notifications, worker attendance check-in/out, paid-hours computation, automatic national holiday imports, recurring event engine, or other unrequested features.

## Stage completion notes (maintained as development progresses)

- **Stage 1:** Implemented and verified. On 2026-10-20, the user's explicit request to start Stage 2 authorized progression from Stage 1. The automated authentication/account checks passed; this note does not claim the user independently completed every manual acceptance step.
- **Stage 2:** Delivered and tested; the user's explicit request to start Stage 3 on 2026-10-20 authorizes progression. See `docs/STAGE2-REPORT.md` for the delivery checks; this note does not claim every manual check was independently completed by the user.
- **Stage 3:** Delivered and tested; the user's explicit request to start Stage 4 on 2026-10-20 authorizes progression. See `docs/STAGE3-REPORT.md` for the delivery checks; this note does not claim every manual check was independently completed by the user.
- **Stage 4:** Delivered and tested on 2026-10-20; the user's explicit request to start Stage 5 on 2026-10-22 authorizes progression. Implemented manager single-date events/manual holidays, actual-today below-grid announcements, current-month-only worker receipts and selectable-month manager yes/no reporting. The local migration is applied; the MariaDB suite passed 227 tests and 1348 assertions with no failures/skips. Desktop/mobile and polling checks passed. See `docs/STAGE4-REPORT.md` for details; this note does not claim every manual check was independently completed by the user.
- **Stage 5:** Delivered and tested; the user explicitly authorized progression to Stage 6 on 2026-10-22. Added worker desk booking/edit/cancel/history, manager add/rename/deactivate controls and atomic desk cancellation on date closure/account deactivation. Desk deactivation immediately cancels running and future bookings, as confirmed by the user. The migration is applied locally with nine initial desks. Final MariaDB suite: 332 passed, 1892 assertions, no failures/skips; responsive/native-form checks passed. See `docs/STAGE5-REPORT.md` and `README.md` for code flow, actual verification and manual review steps. The calendar → receipts → other information order is preserved. Progression to Stage 6 was authorized on 2026-10-22.
- **Stage 6:** Delivered on 2026-10-22, with the selected-month receipt-column follow-up delivered on 2026-10-25. Added the manager-only native POST download, validated inclusive historical ranges through actual Israel today, zero-day/inactive worker rows, correct five-period day counts and Hebrew CSV encoding/escaping/formula-prefix protection. Original full MariaDB suite: 442 passed, 2626 assertions; receipt follow-up: 482 passed, 2877 assertions, with no failures/skips. No migration or live business-data changes were required. See `docs/STAGE6-REPORT.md`. The user's explicit request to start Stage 7 on 2026-10-25 authorizes progression; it does not claim every spreadsheet/browser acceptance step was independently completed.
- **Stage 7:** Explicitly authorized on 2026-10-25. The user confirmed local checks and a local deployment/restore rehearsal now, with Raspberry Pi verification later. Local work is delivered for review: revoked in-flight login writes are rejected under existing locks; narrow native desk-date controls no longer overlap; 500 MariaDB tests / 3758 assertions passed, along with browser, production-bootstrap, Apache access and synthetic backup/restore checks. See `docs/STAGE7-REPORT.md` for actual results, code flow, resource limits and manual steps. Device evidence and final office-pilot approval remain pending.

### Authorized account-management follow-up — 2026-10-22

The user authorized a permanent-delete option for inactive accounts and immediate removal of all currently inactive local accounts, **including their history**. This is an explicit exception to retention during ordinary deactivation. Manager deletion uses an acknowledgement checkbox and delete button without requiring a typed username or password, as corrected by the user on 2026-10-22; it removes the account's shifts, desk reservations, monthly receipts, sessions and photo, and preserves shared blocks/events/desks and other workers' records by clearing the deleted actor references. Active accounts must be deactivated first; the last active manager remains protected. There is no automatic purge. This follow-up does not authorize Stage 6. Implementation and verification are recorded in `docs/ACCOUNT-DELETION-REPORT.md`.

Delivered for review: migration applied locally; one inactive account deleted, zero remain, and all three active accounts (including the active manager) preserved. Initial delivery MariaDB suite: 358 passed, 2046 assertions, no failures/skips. These follow-up checks predate the separate authorization of Stage 6 on 2026-10-22.

Confirmation correction (2026-10-22): removed the typed username field and server requirement. Inactive-account deletion now needs only the acknowledgement checkbox and delete button, with no username/password entry. Focused deletion/concurrency/CSRF checks passed: 34 tests, 182 assertions. No migration or live data operation was needed for this correction.

### Authorized account-reactivation follow-up — 2026-10-22

The user authorized adding a manager option to reactivate inactive accounts. Restore access for the same worker or manager with the existing username, password hash, ID, role, profile, receipt records and history. Canceled shifts and desk reservations remain canceled. Use a native button with no typed credentials; only active managers may invoke it. Reactivation invalidates stored/late old sessions using an account session version, and the account signs in again. Repeated reactivation of an active target is a no-op. Permanent deletion remains irreversible. This follow-up does not authorize Stage 6; see `docs/ACCOUNT-REACTIVATION-REPORT.md` for implementation and actual verification.

Delivered for review: the session-version migration is applied locally; no account was automatically reactivated. The complete MariaDB suite passed 376 tests and 2202 assertions with no failures/skips. Native-form, authorization, stale-session replay and concurrent manager-action checks are included. These follow-up checks predate the separate authorization of Stage 6 on 2026-10-22.

### Authorized manager-list visibility follow-up — 2026-10-22

The user initially authorized limiting the manager dashboard's events/manual holidays and blocked-date lists to the actual current Israel calendar month, keeping other months' records stored. The later correction below supersedes that upper month limit. The receipt-month picker remains independent. A separate archive for both is recorded in `AGENTS.md` as a possible future feature, requiring later authorization. No archive UI or new stage is authorized by this follow-up. The implementation only changes dashboard reads and existing list text; all date-blocking and worker rules remain in force.

Initial delivery: current-month filtering and concise monthly list text were implemented. The full MariaDB suite passed 386 tests and 2330 assertions with no failures/skips, including ten new visibility/boundary scenarios. No migration or data deletion was required. This delivery predated the separate authorization of Stage 6.

**Confirmed correction (2026-10-22):** the user explicitly requested that **all future months' events/manual holidays and blocked dates also appear**. Show dates from the first day of the actual current Israel month onward; hide only past months and retain their records. Use the same lower date bound before pagination and counts, keep ten records per list page ordered by date, and update the existing Hebrew headings/empty messages. `Admin/UserController::index()`, the existing event/block Blade panels and `DashboardMonthVisibilityTest.php` implement this follow-up; `AGENTS.md` and `README.md` record the rule and request-to-view/manual review guide. No migration, business-data deletion, route, service/model, CSS, JavaScript or archive additions are required. Stage 6 remains delivered for review and Stage 7 remains locked.

Correction delivered for review: the full MariaDB suite passed **442 tests and 2627 assertions in 68.74 seconds**, with no failures/skips. The initial sandboxed run could not connect to the socket (zero assertions); the rerun with access to the dedicated test database passed. Pint, Blade compilation and `git diff --check` passed, and the five HTML references remain untouched. `README.md` has manual review steps; owner browser acceptance is pending.

The [extra features and follow-up report](docs/EXTRA-FEATURES-REPORT.md) consolidates the additions/corrections outside numbered stage deliveries, links existing detailed reports and covers the session policy and final manager-list visibility rule. This documentation update changes no stage approval.

### Authorized permanent desk-deletion follow-up — 2026-10-22

The user explicitly requested permanent deletion instead of desk deactivation and confirmed deletion of **all the desk's reservations, including past history**. Managers use an acknowledgement checkbox and native delete button, without typed credentials. Both active and legacy inactive desks can be removed. The transaction deletes the desk's reservations first and then the desk under a desk row lock and the existing manager gate; restrictive foreign keys remain enabled. Other desks, accounts, shifts, receipts, unrelated reservations and permanent date-lock rows stay intact. No automatic cleanup of existing desks is authorized or performed. The old deactivation action is removed, and existing legacy columns remain for database compatibility without a migration. See [the desk deletion report](docs/DESK-DELETION-REPORT.md) for implementation, checks and manual review. Stage 6 remains delivered for review; Stage 7 remains locked.

Delivered for review: the full MariaDB suite passed **464 tests and 2742 assertions in 74.68 seconds**, with no failures/skips, including deterministic deletion/booking/edit/cancel races and a worker-page inter-query deletion regression. A first-run validation-feedback failure was corrected before that passing run. Pint, Blade compilation, route inspection, whitespace and documentation-link checks passed. No migration or live data cleanup was required; owner browser acceptance is pending.

When a stage is approved by the user, record the confirmation date and brief tested deliverables here before starting the next authorized stage. Keep the human-approved stage gates intact.

### Authorized deletion-wording correction — 2026-10-22

The user requested simpler Hebrew wording for both desk and account deletion. Labels now use `מחיקה`, `מחיקת החשבון` and `מחיקת העמדה`; descriptions, acknowledgement text, validation and status messages use the same familiar wording. Warnings still explain which records are removed and that deletion cannot be undone. Only text and matching documentation/fixture wording change; authorization, acknowledgement requirements, transactions, data retention/removal and stage approvals remain unchanged. See [the extra features report](docs/EXTRA-FEATURES-REPORT.md) for verification.

Verified: existing focused account/desk deletion and deleted-account CSV checks passed **51 tests and 296 assertions in 14.10 seconds**, with no failures/skips. Pint, Blade compilation and whitespace checks passed. No migration or live data change was needed.

### Authorized CSV receipt-column follow-up — 2026-10-25

The user requested a fourth CSV column, `קבלות`, showing `יש` or `אין`, and confirmed that the manager should select the receipt month. The native download form now requires `חודש קבלות`, defaulting to the actual current Israel month, separately from both workday dates and the dashboard receipt-list filter. The existing export query reads the selected month's boolean with a LEFT JOIN, preserving zero-day/inactive workers and one count per non-canceled shift date. Missing and unchecked records both produce `אין`. The download filename includes the receipt month. No migration or business-data change is needed. See [the Stage 6 report](docs/STAGE6-REPORT.md) for the request flow, verification and manual review steps. Stage 6 remains delivered for review; Stage 7 remains locked.

Verified: the full MariaDB suite passed **482 tests and 2877 assertions in 77.62 seconds**, with no failures/skips. Pint, Blade compilation, route inspection and whitespace checks passed. Initial database-connection attempts completed no assertions; the persistent instance was started safely before the passing run. Spreadsheet and owner browser acceptance remain pending.

### Stage 7 local delivery — 2026-10-25

The user's request to move to Stage 7 authorizes progression from Stage 6, including the receipt-column follow-up. The user confirmed local verification now and Raspberry Pi verification later. Local delivery includes two corrections: reject stale account versions inside manager/worker/profile transactions, and stack the desk date controls at the existing narrow breakpoint to prevent overlap. No new production route, migration or feature system is introduced.

Final MariaDB suite: **500 passed, 3758 assertions, 76.73 seconds**, with no failures/skips. Eighteen new cross-feature/interface/security cases are included. Chromium comparison covered all five references and 30 page/viewport combinations; native forms, independent receipt-month CSV, blocked desks, polling lifecycle and the narrow-control correction passed. A temporary optimized production copy passed Apache HTTPS/public-root/private-file and restricted runtime-account checks. Two disposable synthetic schemas passed a real database/files backup and restore with matching row/schema/image hashes and constraint checks. A 100-worker, 26,100-shift synthetic CSV probe measured 331 ms and 24 MiB peak PHP allocation on the development host. Details and limits are in [the Stage 7 report](docs/STAGE7-REPORT.md); [DEPLOYMENT.md](docs/DEPLOYMENT.md) supplies the portable runbook and Apache example.

The first new workflow fixture omitted the existing deactivation acknowledgement and was corrected before the passing runs. The rehearsal's temporary database-process interruption was resolved without resetting data. Local success does not verify Raspberry Pi capacity, office-trusted HTTPS, off-device media or reboot/recovery behavior. Those checks and owner pilot sign-off remain pending; no final stage checkbox is marked complete.
