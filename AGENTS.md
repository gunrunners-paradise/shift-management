# AGENTS.md — מערכת נוכחות

> Permanent Codex instructions for a staged Laravel application. **Always read `ROADMAP.md`.** A feature described here or appearing in a prototype is NOT permission to implement it ahead of its approved stage. **Latest approved shift policy: exactly five periods (four overlapping four-hour periods and one full-day period), replacing the former three-period model.**

## 1. Agent workflow and approval gate

1. Before coding, read **this entire file**, `ROADMAP.md`, all five HTML references below, and the existing Laravel code and tests. Inspect their responsive CSS and interactions, not just their titles. If the next stage changes shift presentation, obey the latest approved five-period rules rather than obsolete mockup content.
2. Identify the **single stage explicitly authorized by the user**. Without authorization, discuss/plan only. If the user authorizes Stage N, implement Stage N **only**, including necessary supporting code and its tests. Do not pre-create later tables, routes, models, placeholder controls, or systems merely because their final design is visible.
3. Present a concise plan, including routes, controllers, views, migrations, JS/CSS and tests expected to change. Keep existing approved features intact.
4. Resolve genuine business-policy ambiguity with the user before coding the affected part. Never silently decide an unapproved policy. Only put a decision into these instructions once approved.
5. Run the available automated tests and describe manual verification steps. Report actual results and outstanding issues accurately.
6. **Stop at the end of the stage.** Wait for the user's explicit review/approval before updating stage status and proceeding to the next stage. Do not automatically build multiple stages.

`AGENTS.md` contains the confirmed business rules, development standards, and design priorities. `ROADMAP.md` defines scope and tests **per stage** and contains stage progress/approval notes.

## 2. Source-of-truth HTML prototypes

These **exactly named** five files sit **alongside `AGENTS.md` and `ROADMAP.md`** in the repository root. They are **read-only design references**, not production pages to deploy. **Do not look for numbered copies such as `shift-calendar(3).html` and do not require alternate prototype filenames.**

| Reference | Intended page |
|---|---|
| `login-prototype.html` | Guest login, minimal header, centered logo/login form, password visibility. |
| `shift-calendar.html` | Worker landing page: Sunday–Thursday weekly calendar with **five shift rows** (09:00–13:00, 10:00–14:00, 11:00–15:00, 12:00–16:00, full day 09:00–16:30), receipts, birthdays, holiday announcement and today's coworkers. Contains demo controls/data. |
| `worker-desks-prototype.html` | Worker `עמדות` page, date selection, desk grid, time booking, occupied intervals, own reservations and cancellation. |
| `worker-profile-prototype.html` | `הפרופיל שלי`, profile summary, personal information/photo, separate password-change form. |
| `admin-management.html` | Manager dashboard: accounts, events, blocked dates, CSV, desks, monthly receipts and in-page quick actions. |

Logo: prototypes reference `enter_digital_studio.svg`. Use that actual file from the project if supplied, served through a proper Laravel public asset URL. If missing, ask for the asset; never invent a logo or publish the reference HTML as production routes.

### Mandatory conflict resolution

These prototypes were edited separately and **do not always agree** about navigation, CSS selectors, sample data, JS storage, or even permissions. Resolve in this order:

1. User's latest **confirmed** business/design decision.
2. `AGENTS.md` and the **authorized** `ROADMAP.md` stage.
3. The prototype **for that particular page's layout**, as opposed to its demo implementation.
4. Shared styles and Laravel conventions.
5. A prototype's standalone JavaScript, fake records, localStorage keys, obsolete links and stub controls — **lowest authority**.

Specific reconciliations:

- Use **one shared authenticated Blade header/layout**. In RTL, far **right**: circular organization logo; hamburger immediately **to its left**; then `מערכת נוכחות`. Opposite end: account avatar/profile dropdown. Use real Laravel **named routes** for menu items (never other `.html` filenames or fake buttons); mark current route active. Different roles see appropriately authorized destinations.
- Login is deliberately different: blue top bar with `מערכת נוכחות`, **no hamburger, account menu, avatar or logout**; a large rounded logo appears **above the inputs in the centered card**, matching `login-prototype.html`.
- Workers have shift calendar and desk pages. **Managers are administration-only**; they cannot register themselves for shifts, reserve desks, or submit worker receipts. The manager prototype's calendar menu entry is an obsolete demo link, **not** authorization. Workers go to calendar after login; managers go to admin page. Both roles get their own profile page when Stage 3 arrives.
- Hamburger navigation is **between real site pages**. The own-profile link belongs **only in the avatar/account dropdown**, not in the hamburger menu. Only the admin dashboard's separate **quick-action** buttons scroll to management sections (`users`, `events`, `blocks`, `reports`, `desks`, `receipts`) *once those sections exist*. Quick actions must never lead to absent sections.
- Share a single color/spacing/typography/CSS token system and common Blade components for header, dropdown, panels, buttons, forms and messages. Do not paste multiple contradictory global `.field`, `.panel`, `button`, `input` rules into different pages. Scope feature CSS. Preserve each page's unique layout: **admin dashboard is not a worker shift calendar**.
- Latest confirmed calendar layout (2026-10-20): **calendar first, receipts box immediately below it, then all other information**, including announcements, birthdays and coworkers. This order applies on desktop and mobile and overrides the references' above-calendar widgets. Weekly navigation, date headings and the calendar legend remain with the calendar. Only render features authorized for the current stage.
- Reference design: `<html lang="he" dir="rtl">`, Arial/`Noto Sans Hebrew`, background about `#f4f6fb`, blue/indigo header `#3457be`–`#4636bf`, blue primary buttons `#4563d8`–`#598ee1`, soft shadows, rounded white panels, purple chosen shift, pale red alternative slots, strong red blocked slots; keep mobile breakpoints effective. RTL logo/hamburger order matters.
- Hardcoded workers, localStorage accounts, example birthdays/holiday, mock coworker button, manager block-simulation toggle, demo logout, obsolete static page URLs and mocked password changes **must not** be shipped as real behavior. Real state comes from the authenticated server and MariaDB. A future-stage widget must not appear functional merely because it is present in a reference.
- When a material visual conflict cannot be resolved by shared design rules, ask the user. Do not redesign arbitrarily or sacrifice existing behavior for stylistic uniformity.
- **Special case: the five-shift change wins over legacy prototype content.** If `shift-calendar.html` still shows three shift rows, treat it as a visual template only: rebuild its grid with the **five confirmed rows**. The named reference file remains `shift-calendar.html`. Never preserve the obsolete morning/afternoon choices just to imitate an older HTML file. Keep every unrelated calendar feature and the overall look intact.


## Readable, well-commented code — mandatory

The project owner will **study the generated source code**. Write it to production-quality standards **and** make it easy to understand. Favor clarity over shortness or cleverness.

- Prefer **descriptive English identifiers**, straightforward control flow, early returns, ordinary `foreach` loops when clearer, and small focused methods. Do not compress complex logic into one-liners, deeply nested ternaries, chained callbacks, metaprogramming or unnecessary design patterns.
- Follow idiomatic Laravel MVC: **controller = receive request and coordinate**, **FormRequest = validate input**, **policy/middleware = authorize**, **model = persistence/relationships**, **service = nontrivial reusable business workflow**, **Blade = presentation**, **vanilla JS = small browser interactions**. Do not put transaction logic in Blade or mix HTML markup construction into controllers.
- Add **useful comments** explaining *why* rules exist, not a comment on every obvious assignment. For nontrivial methods, include a short PHPDoc describing the purpose, inputs, return value (if not obvious), side effects, and any important authorization/transaction assumptions. In services, annotate **why locks and DB transactions are needed** and which invariants they protect. Explain date/time boundaries, cancellation/history behavior and the one-active-record constraint where implemented.
- In JavaScript, document the DOM elements being updated, the polling lifecycle and server endpoint contract. Use readable named functions for nontrivial behavior. Prefer native forms when possible.
- Separate feature-specific files sensibly. Avoid both giant all-in-one classes and dozens of tiny abstractions that make a simple feature hard to trace. Don't add libraries or technologies solely to reduce a few lines of ordinary PHP.
- Use consistent formatting and common PHP/Laravel conventions (Laravel Pint/PSR-12 when available). Keep comments accurate when editing code. Keep names, comments and technical docs in **English**, while **visible interface labels and errors stay Hebrew**.
- Make the learning path traceable: for each finished stage, briefly explain **which route calls which controller, which service/model changes the DB, which Blade file renders the result, and which JS file enhances it**. Give filenames and method names in the stage report so the owner can follow the request end-to-end.
- Add **clear tests named after the business scenario**, with readable Arrange–Act–Assert phases and a short comment only where the reason for a setup or assertion is not self-evident. Prefer small conventional solutions that another PHP developer can maintain.

## 3. Architecture and runtime

- **Laravel MVC**: controllers, Eloquent models, Blade templates, web routes with names, Form Requests, middleware/policies, migrations, tests. Use a small service for complex transactional operations such as date closure or desk booking, not many empty wrappers.
- **Blade + ordinary CSS + vanilla JavaScript**, not React, Vue, SPA, Livewire, or a new component library. The **shift grid is rendered by PHP/Blade on the server**; JS is for dropdowns, confirmation/modal conveniences and a small coworker refresh, not the main calendar DOM.
- **MariaDB (InnoDB)**, foreign keys, indexes and transactions. Select the correct Laravel database driver based on the installed version; a supported MySQL PDO connection may connect to MariaDB. Do not silently replace MariaDB with SQLite. Test concurrency-dependent logic against MariaDB.
- Host target: Raspberry Pi 4 **8 GB RAM, 32 GB storage**, Linux/Apache/PHP/MariaDB on office LAN, later migratable to company central server. Do not hardcode IPs, filesystem paths, credentials or Pi-specific assumptions. Apache document root points to Laravel **`public/`** (never repository root). Avoid Docker, Redis, WebSockets, queue workers, JS frameworks or other infrastructure unless separately approved.
- `Asia/Jerusalem` business timezone, Hebrew interface, dates formatted for Hebrew UI while saved in unambiguous DB types. Strict separation of local calendar **dates** and timestamp comparisons.
- Prefer conventional session-based Laravel authentication over JWT or localStorage sessions. Use CSRF, server validation, escaped Blade output, authorization, login rate limits and safe errors. No client-stored authoritative booking/profile data.
- Confirmed session policy: **30 days of inactivity** (`SESSION_LIFETIME=43200` minutes), renewed by application requests. Explicit logout or account deactivation still ends authenticated access immediately. Keep the session configuration default and `.env.example` consistent with this policy.

### `.env`, and password handling

- Laravel uses an untracked **`.env`** for deployment-specific secrets (DB username/password, APP_KEY, APP_URL, session configuration, etc.). Supply only safe placeholders in `.env.example`, plus `.gitignore` protection. Do not place real secrets in this document, Git, frontend JS, Blade, migrations, fixtures or logs.
- **User-account passwords belong as secure hashes in MariaDB**, not a list of plaintext passwords in `.env`. Managers create accounts and supply/set initial passwords through the authorized workflow. The first manager is created through a secure one-time CLI/bootstrap command or safe interactive setup; **never ship `admin/admin`** or any fixed initial credentials.
- **Ensure the operator can sign in after first-manager setup.** Prefer an interactive CLI command that asks the operator to choose a username and password, with password input hidden and confirmation required. After successfully creating the account, display the **username, manager role, and configured login URL** with clear sign-in instructions. The operator already knows the password they selected; **never print, log, email, commit, or store that plaintext password**. If using a system-generated temporary password instead, reveal it securely only once and require a password change at first login. Do not duplicate the first manager or reset an existing manager password if setup is repeated. Document the exact setup command and login steps for local testing and the Raspberry Pi.
- On the Raspberry Pi the operator fills in actual `.env` values. Use `APP_DEBUG=false` for production, least-privilege DB accounts, appropriately secured session cookies, HTTPS when handling logins (including on LAN where practical), and a documented backup + restore procedure. Never commit uploads, secrets or actual backups.

## 4. Users and role authority — confirmed

- Two roles: `worker` and `manager`. No worker self-registration. A **manager may create workers AND other managers** and deactivate accounts. Managers **do not have worker shift/desk/receipts privileges**, even when they guess endpoints. Managers can access the admin dashboard and, from Stage 3, their own profile. Protect against accidentally deactivating the last active manager.
- Account fields: stable ID, unique username, first/last name, gender choice, birth date, photo path, role, active flag, password hash, timestamps. Never cascade-delete historical work records when deactivating an account.
- Workers can update **their own** name, gender, birthday and avatar and change their password after verifying the current password. Username is read-only in the worker profile. Users cannot elevate their own role.
- Manager role display for selected gender: male `מנהל`, female `מנהלת`; unspecified/other gender must not be guessed and should use neutral `מנהל/ת` wording.
- On **worker deactivation**, cancel their **future shift selections**, **today's upcoming or in-progress shift**, and **future desk bookings**, preserving cancellation history, and **immediately cancel any desk booking already in progress**. Preserve completed shifts (including today's shift once its configured end time is reached) and earlier dates. Disable future booking actions and reject/revoke their existing authenticated sessions, not just their next login. Cancellation of an in-progress shift was confirmed by the user on 2026-10-20.
- Photo uploads must be validated (file type/size), stored safely as files, not SQL base64; have non-executable storage, safe URL and default avatar. Actual image upload functionality is Stage 3.

## 5. Shift scheduling — confirmed

- Worker landing/calendar is **Sunday to Thursday**, weekly navigation previous/next/current. Main weekly grid shows **only that worker's** selections. Managers cannot make personal selections. No Friday/Saturday shifts and **no work-from-home choice**.
- Exactly **five** allowed categories. The old `morning` and `afternoon` categories are **removed/replaced**; do not expose them as extra choices:

  | Stored code | Hebrew display | Start | End | Duration |
  |---|---|---|---|---|
  | `09_13` | `משמרת 09:00–13:00` | 09:00 | 13:00 | 4h |
  | `10_14` | `משמרת 10:00–14:00` | 10:00 | 14:00 | 4h |
  | `11_15` | `משמרת 11:00–15:00` | 11:00 | 15:00 | 4h |
  | `12_16` | `משמרת 12:00–16:00` | 12:00 | 16:00 | 4h |
  | `full` | `משמרת מלאה` | 09:00 | 16:30 | 7h 30m |

- Keep the category code, label and hours in **one easily readable source of truth** (e.g. a small `config/shifts.php` array, or a typed enum paired with start/end-time definitions). **Do not duplicate definitions** in controllers, Blade, services, validation and JS. The server validates only these five codes. Prefer a stable short string column over a fragile database `ENUM` that would require schema changes for future schedule adjustments; define labels/times in code. These are **scheduled** times, not check-in/attendance logs.
- Exactly **one active shift** per worker/date, or none (`בלי משמרת`). All five categories **overlap in time by design**; this is permitted because the worker may select only one period, and there is **no per-period worker capacity limit**. Office choice remains `משמרת חממה`. Choosing a different category **replaces** the previously active selection for that date; choosing none cancels/clears it while **preserving cancellation history**. No multiple shifts per worker on the same date.
- **No worker-count capacity limit** per shift. Worker can register/change for **today or the next 28 calendar days**, not past dates, not beyond the horizon. Today's shifts remain editable **for the whole current day**, even after their start times. The PHP backend enforces all date, role and blocked-date restrictions. Worker can browse noneditable dates read-only.
- Blade calendar state: **five shift rows** with Sunday–Thursday columns and a date header; selected period pale purple; the **other four** available period boxes for the same worker/date pale pink/red **and still clickable**; unselected day white; blocked date **all five** boxes strong red **and disabled**. Keep the five rows compact/responsive for narrow screens, while retaining readable time labels and touch targets. Include text/accessible status cues; color alone is insufficient.
- A day's planned work counts as **one scheduled workday** for CSV regardless of whether its single active shift lasts 4h or 7h30m; no actual attendance verification, clock-in/out, breaks or paid-hours accounting is implied.
- Below calendar, separate **`מי עובד איתי היום?`** section: workers scheduled for **actual today** (including logged-in worker if registered), worker names/avatar/**correct one of the five shift periods**; exclude manager accounts and canceled shifts. This describes **planned staffing**, not physical presence. Initial Blade render; optionally poll authenticated JSON endpoint about **every 30 seconds**, pause in hidden tab, refresh when visible/after own shift change, update list only. No WebSockets needed.
- Below calendar, birthday greeting only for genuinely matching **today's day/month** from active worker birthdays; may show multiple people, never reveal year or age, never always-on example notices. Confirmed leap-day policy: celebrate February 29 birthdays on **February 28 in non-leap years**, and on February 29 in leap years.

## 6. Manager blocking, events and holidays — confirmed

- Manager may **block today or any future date**, **never the past**, with optional reason and confirmation. Blocking a date prohibits new worker shifts **AND desk reservations** for that date; existing shift and desk reservations are **canceled**, with canceled history preserved. Backend action should be transactional and safe under concurrent booking requests. Server rejection is mandatory, not just disabled UI.
- When a blocked date is **unblocked**, all previously canceled selections/bookings stay canceled. Workers must re-register/rebook. Don't restore anything implicitly.
- Events and manually named/entered **holidays are informational**. They may show a holiday notice but **do not close the office**. The manager must separately block a date to prevent registrations. Announcements should appear only when genuinely applicable to today, not always-visible prototype sample text. The event prototype is single-date; multi-day events are unapproved.
- Stage 2 builds blocking and shift cancellation first; Stage 5 extends the **same** block operation to desk reservations. Do not introduce unused desk tables/controllers in Stage 2.

## 7. Desk bookings — confirmed

- Worker-only `עמדות` page; manager adds/removes desks on admin page but **cannot reserve for themselves**. Begin with **nine active desks** (`עמדה 1` … `עמדה 9`), stable IDs; renaming a desk must not change its identity/history.
- Desk reservation is **independent of shift registration**. A worker may reserve on a date without a shift. However, manager-blocked dates forbid/cancel **both** kinds.
- Desk booking permitted **Sunday–Thursday**, date today through **today + 28 calendar days**. Office/desk hours **09:00–16:30**, Israel time. Start and end times must be aligned to **30-minute boundaries** (`:00` or `:30`). Minimum reservation **60 minutes**; end strictly after start; no overnight bookings. Bookings may end **exactly 16:30** but not later.
- Today may be booked only if the booking starts **strictly later than the current server time**. Worker can edit or cancel **only strictly before the booking's start time**. In-progress bookings cannot be self-canceled. A manager's blocked-day action or worker deactivation still cancels them as required by the rules above.
- Worker can hold **at most one active desk reservation per date**, regardless of disjoint hours or desk choice. Canceling releases that slot; history stays available. Same desk/date must not have **overlapping active** bookings: `existing.start < requested.end AND existing.end > requested.start`; adjacent bookings touching at endpoints are permitted.
- Prevent **concurrent double bookings** with a MariaDB transaction and deterministic locking for both the worker/date and desk/date, not an unsafe pre-check. Likewise serialize blocking/reopening with bookings for the **same date**, including when a blocked-date row does not yet exist (checking an absent row is not sufficient locking). Document a stable date-level lock strategy and include race tests. Unique database constraints should support one-active selection where practical, but a simple unique index does not prevent arbitrary overlapping time intervals.
- Manager removal: warn about bookings, confirm, **deactivate** the desk rather than hard-delete, cancel its **future** bookings and retain history. Whether an **already-running** booking must be canceled when the *desk* is deactivated is not yet decided; ask in Stage 5. Do not mix that with the **confirmed** immediate in-progress cancellation on **worker deactivation**.
- The colored availability grid is only a hint; server and DB always validate current availability.

## 8. Monthly `קבלות` and CSV — confirmed

- Worker calendar has `יש לי קבלות החודש`: **boolean checkbox**, unchecked/absent record = `אין קבלות`, checked = receipts to send to manager. **Never add an unanswered third state.** Unique worker+calendar-month, month keyed to **actual current month** (not the week viewed). Manager admin `מעקב קבלות חודשי` shows workers' yes/no states and counts for selectable month; managers don't submit receipts for themselves. Confirmed on 2026-10-20: workers may edit **the current month only**. Past and future months cannot be changed by workers, including through a stale form after a month boundary.
- **CSV export is exclusively on demand, initiated by a manager.** In the administration page, an authorized manager selects an inclusive date range and clicks **הורדת CSV**. Only that explicit request causes Laravel to query MariaDB and generate/stream the downloadable report; simply opening the dashboard must not produce one. **Never** add scheduled, recurring, cron-triggered, automatically generated, queued, emailed, or periodically saved reports. The report counts **scheduled shifts, not verified physical attendance**. For each worker, one day per non-canceled shift date (**any of the five approved shift categories counts exactly one day**). Columns **`שם העובד`, `מספר ימי עבודה`, `תאריכי עבודה`**, in that order. Last column is a *single quoted CSV cell* containing chronological dates separated by comma and space. Use UTF-8 BOM for Hebrew Excel, correct escaping, formula-injection protection, and DB records (not localStorage). Historical shifts for deactivated workers remain available. Ask in Stage 6 if inclusion of zero-day workers or future planned dates matters.
- Manager admin page must remain a management dashboard with stage-appropriate sections for accounts, blocked dates, events, desk controls, receipts, CSV. Quick-action in-page links only for sections actually implemented; no worker calendar selection inside the admin page.

## 9. Database and integrity implementation principles

- Add migrations/models **only in the stage requiring them**. Domain concepts eventually include users, shifts, shift cancellation/audit records, blocked dates, calendar events/holidays, desks, desk reservations and status/cancellation history, monthly receipts.
- Store and validate **only the five configured period codes**. Ensure one **active** shift per worker/date even when canceled history must be preserved. Do not impose a naive uniqueness rule that prevents valid rebooking after cancellation; choose a schema with clear active selection and audit trail. Canceled records do not count in current calendar, roster, available desk slots or CSV.
- All ownership/role/date/time validation runs server-side. Use DB transactions for cross-record changes (blocking dates, worker deactivation, desk deactivation). Lock consistently and guard against new reservations racing with cancellation/blocking. Where the date has no preexisting blocked row, provide an actual date-specific serialization mechanism, not just read-before-write.
- Preserve stable user/desk IDs and history; don't cascade delete reports/registrations when deactivating something. Separate `DATE` from timezone-aware timestamps. Store who/when/why for cancellation when feasible. Repeated block/deactivate operations should be safe/idempotent.

## 10. Design and security quality bar

- Shared Blade layouts/components, named routes and coherent CSS tokens. Preserve mobile and RTL layouts from prototypes, including narrow calendar behavior. Prefer a consistent scrollbar approach that doesn't force mobile scrollbars. Do not import conflicting page-wide selectors.
- Use semantic links vs buttons, keyboard focus states, Hebrew validation feedback, modal/dropdown keyboard handling, accessible labels and descriptions, and touch-friendly targets; convey red/purple states with text as well as color.
- For primary business flows use ordinary Laravel forms, CSRF, validation and redirects; vanilla JS is progressive enhancement. Never treat browser localStorage as authoritative.
- Write/test per-stage security and business rules, authorization, concurrency, date window, cancellation history, CSV and dynamic announcements as they become relevant. For transaction/race behavior, **MariaDB integration tests are required**; SQLite does not validate MariaDB locking semantics.
- Security: session hardening, protection from CSRF/XSS, safe image uploads, login throttling, password hashing, active-account guards, role policies, no secrets in source or logs. Don't claim tests passed if they were not run.
- Deployment: document Apache `public/`, PHP extensions, MariaDB least privilege, `.env`, storage links and permissions, log management, backups and tested restore; on a 32 GB device store backups off-device, avoid runaway logs/uploads. Keep deployment portable to company server.

### Migration safety if an older scheduling implementation already exists

If real tables or worker records already contain `morning`, `afternoon` or an earlier three-period representation, **do not automatically translate them**: the old periods do not correspond exactly to any one new four-hour category. Inspect the existing data, propose a safe migration/history strategy, back up the records, and request user approval **before altering or discarding existing shifts**. For a fresh Stage 2 build with no prior shift data, use only the five approved codes from the start.

## 11. Open policies — defer to the relevant stage, don't invent

- Stage 2: managers editing an individual worker's shift remains unauthorized. Worker deactivation cancels an in-progress shift too, as confirmed on 2026-10-20.
- Stage 3: February 29 birthdays use February 28 in non-leap years, as confirmed by the user. Manager editing of other users' profile fields beyond account creation remains unauthorized.
- Stage 4: workers edit **the current month's receipts only** (confirmed); past/future months are read-only. Events remain single-date; multi-day events require separate approval. Holidays are **manual**, not imported automatically.
- Stage 5: how to handle a **desk deactivated during an in-progress booking**; extra manager booking overrides are not approved.
- Stage 6: inclusion of workers with zero planned days and future scheduled dates within export range, if a choice is necessary.
- Outside approved scope: check-in/clock-out, SMS/email notifications, paid-hour calculations, automatic Israeli holiday imports, recurring events, shift caps, new roles, or entirely new pages not specified by the user.

## 12. Final stage reporting

Every completed stage must include: changed files, implemented versus deferred scope, migrations/commands, actual tests and failures, manual acceptance steps, and remaining issues. **Wait for explicit approval** before unlocking the next stage in `ROADMAP.md`. The HTML reference files are **read-only** and should never be rewritten to make an implementation appear closer to the prototypes.
