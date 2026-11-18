# Shift Management + Mishmoret

Integration in `ariannamethod/shift-management`, 2026-11-17. The original Laravel
application remains at the repository root. Its three literal-password entry
points are repaired independently of the native runtime.

PHP generates `web/index.html` from `templates/index.php`. The C process serves
that HTML, the fork's CSS/logo, vanilla JavaScript and the authenticated JSON API.
SQLite holds accounts, sessions, schedules, desks, pictures and pending signups.
Itzik runs the embedded Wolfe engine locally. No browser dependency, remote AI
service, Laravel, Composer or MariaDB is needed to run this version.

## Run

Build dependencies: C11 compiler, make, pkg-config, libmicrohttpd, SQLite3,
libsodium and json-c. Linux additionally links libxcrypt (`libcrypt-dev` on
Debian/Ubuntu) for existing bcrypt passwords. PHP CLI is needed when rebuilding
the HTML template; the generated HTML is included for deployment.

```sh
cd native
make
scripts/manage.sh start
# Read data/initial-accounts.json locally; change the initial password on login.
# Open http://127.0.0.1:8086
```

On Homebrew use `PKG_CONFIG_PATH=/opt/homebrew/opt/sqlite/lib/pkgconfig make` so
headers and linked SQLite come from the same installation. For a Raspberry Pi,
build on its Linux installation with the same libraries. Pi installation has
not been performed in this integration.

`SHIFT_DATA_DIR`, `SHIFT_PORT`, `SHIFT_ORIGIN` configure the management script.
The listener binds to loopback. Put HTTPS in front of it, set the exact public
origin including any port, and keep the data directory private. Set
`SHIFT_TRUST_TAILSCALE_PROXY=1` only behind Tailscale Serve/Funnel, which replaces
its forwarded-client header. Other proxies need their own trust configuration.

## Behavior carried from the fork

- Five periods: 09–13, 10–14, 11–15, 12–16 and full day 09–16:30. Sunday–Thursday;
  from Israel's actual today through today + 28 days, inclusive.
- Workers see their own weekly shifts. Managers now see everyone's weekly grid.
  Managers do not book their own shifts or desks.
- Shift changes remain allowed through the end of that calendar day. Replacing
  or canceling retains the previous record and cancellation reason.
- Desk reservations are independent of shifts: half-hour boundaries, at least
  one hour, one active reservation per worker/day, no overlapping use of a desk.
  A worker changes/cancels only before its start. Adjacent bookings are allowed.
- Closing a date cancels its shifts and desks in one transaction. Reopening
  does not restore canceled records. Informational holidays do not close a day.
- Deactivation revokes sessions and cancels future/in-progress bookings while
  retaining completed history. Reactivation retains identity and password.
  Permanent account deletion requires inactivity. Desk deletion removes that
  desk's reservation history, after explicit confirmation.
- Personal profile and private JPG/PNG/WebP avatars; 2 MiB, dimensions ≤2048.
  February 29 birthdays appear on February 28 in non-leap years.
- Worker receipt declaration for the current month; manager monthly overview.
  Explicit CSV download includes retained workers with zero days, canceled
  shifts excluded, range ending no later than today. These are scheduled days,
  not a measured attendance record. Cells are quoted and formula-prefixed values
  neutralized.
- `/signup/worker` and `/signup/manager` are separate guest pages, absent from
  navigation. Both start closed. The URL fixes the requested role. Approval
  binds a frozen cohort to the manager/session for 30 minutes; later arrivals
  stay pending. Conflicts stay pending with an explanation. Lists use 10 rows.

Itzik's original portrait is copied byte-for-byte from Mishmoret. He understands
hourly choices, asks for missing hours, and proposes a shift for explicit
confirmation. Confirming uses the same scheduling API and rechecks availability.
He can open the weekly view. Desk booking through chat is not implemented.
`wolfe/examples.original.he.jsonl` preserves the source corpus; the active corpus
uses this application's hourly policy. The Hebrew greeting and thanks replies
are small local additions.

## Accounts and protection

New passwords use libsodium Argon2id, minimum 12 Unicode characters, maximum 128
UTF-8 bytes (the old bcrypt application's limit was 72 bytes). Hash-shaped input
is always a literal password. Imported bcrypt stays distinct from user input;
on Linux, successful verification upgrades it to Argon2id. Existing bcrypt
accounts must be tested on Linux, not the macOS development runtime.

Sessions use random tokens, hashed storage, HttpOnly/SameSite cookies, Secure on
HTTPS and 30-day inactivity expiry. Mutations require the exact Origin and a
session CSRF token. Password changes, resets and deactivation revoke access.
KDF work is budgeted by client source. Data writes use prepared statements;
reservation changes and date closures use SQLite `BEGIN IMMEDIATE`. Static
assets have an exact allowlist; user text is rendered with DOM text nodes.
Avatars are bounded raster blobs behind authentication, never public uploads.

## Import existing learners

Obtain an export from the actual installation before changing it. The demo has
synthetic accounts only. Keep a backup of that installation and retain the
private export, which also preserves source metadata not shown by the new UI.

1. On the legacy installation, from the repository root:

   ```sh
   php native/scripts/export-laravel.php /private/path/shift-snapshot.json
   ```

   This reads a transaction snapshot, including private avatars, and creates an
   exclusive, private output file. Source tables are unchanged. `--testing`
   explicitly selects `.env.testing` for a disposable integration fixture.

2. Transfer the export privately to the Linux target. Create a **new** SQLite
   database with the importer, not the active demo or an existing installation:

   ```sh
   python3 native/scripts/import-data.py snapshot /private/path/shift-snapshot.json /private/path/candidate.db
   ```

   User/desk/shift IDs, cancellation records, passwords and pending requests are
   retained. Sessions are deliberately not transferred; everyone signs in again.
   Foreign keys, integrity and desk overlap are checked before publication.
   A manifest records source SHA-256 and row counts. Existing destinations are
   refused. Failed candidate directories are retained for inspection. Earlier
   Mishmoret morning/afternoon data is not automatically mapped to hourly slots.

3. Compare counts and representative histories with the source, log in with an
   existing worker and manager, then point the service at the verified candidate.
   Keep the original installation and snapshot for rollback.

The original worker CSV format is also supported as an offline operator action:

```sh
python3 native/scripts/import-data.py csv workers.csv /private/path/shift-management.db --dry-run
# Stop the server before the actual import:
python3 native/scripts/import-data.py csv workers.csv /private/path/shift-management.db
```

Required columns: `username,first_name,last_name,gender,password`; optional
`birth_date`. Existing usernames are skipped; the validated batch commits
atomically. Plaintext CSV passwords, including hash-shaped strings, go to the C
Argon2id helper via stdin, never command arguments. Keep the CSV private.

## Backup, restore and verification

```sh
scripts/manage.sh backup
# Stop the service before restore; keep an additional backup off the device.
scripts/manage.sh restore /private/path/backup.db
make check
make sanitize
make check
```

The SQLite backup API supports a live backup. Restore validates the private
candidate and revokes restored sessions before publication. Import never writes
to a running server's locked database.

`tests/test_native.py` exercises real HTTP against disposable SQLite databases.
`tests/test_import.py` exercises complete snapshot and CSV imports. Linux adds
the legacy bcrypt login/upgrade test. `tests/browser.cjs` uses externally
installed Playwright and a private demo credential file; it is an operator test
and adds no application dependency. Screenshots are kept outside Git.

## Provenance

C substrate, management script, original Hebrew corpus and Itzik portrait:
[`ariannamethod/mishmoret`](https://github.com/ariannamethod/mishmoret), commit
`77fb360`. Wolfe's pinned revision, license and two inherited local fixes are
recorded in `vendor/wolfe/README.md`. Native code is under GPL-3.0 in `LICENSE`.
The original fork's design assets and business rules remain attributable to its
existing history. See [questions for Reut](../QUESTIONS_FOR_REUT.md).

## Demonstration and recorded checks — 2026-11-17

Polygon demo: <https://polygon.tail42b836.ts.net:8443/>. Separate user service
`shift-management-demo.service`, loopback port 8086, data directory
`/home/ataeff/shift-management-demo-data`. The original Mishmoret service and
HTTPS port 443 remain in place. The demo contains one manager and six fictional
workers. Credentials are private and excluded from Git. The user-service unit
is included in `deploy/` as the exact Polygon configuration.

- Legacy PHP regression selection: 262 passed, 1,638 assertions. The new three
  literal-password cases failed before the repair and passed after it.
- Native Linux: 18 HTTP/import/backup cases passed, including bcrypt upgrade.
- Native macOS with AddressSanitizer/UndefinedBehaviorSanitizer: 17 passed;
  the Linux-only bcrypt case was skipped.
- Browser: manager/worker views, seven admin sections, account creation and
  removal, Itzik, desktop and 390px mobile. Screenshots inspected; numeric hour
  intervals now explicitly use left-to-right layout within the Hebrew page.
- Actual test MariaDB export → fresh SQLite import completed with two synthetic
  accounts. Existing learner records have not been supplied or imported.
- Portrait SHA-256 matches Mishmoret:
  `de40f8823e38fe496ae5315e5412d114da329d9fd2485b39dad28bfd11cfcdf5`.

### Itzik wording correction — 2026-11-18

Oleg's two screenshot requests (`קבע לי ליום ראשון בבוקר` and
`קבע לי בחממה ביום ראשון בבוקר`) returned `no_call` in the initial integration.
The earlier check covered `תרשום אותי` and missed these ordinary formulations.
Added 65 positive/negative examples, additional numeric-hour aliases, polite
weekly-view requests and short help/greeting replies. A worker's vague morning
request asks for exact hours; an hour-only follow-up retains the requested day.
Managers receive the role explanation before being asked for booking details.
API errors now appear in the chat itself. Proposal confirmation and all existing
booking permissions remain in force. The screenshot regression includes both
roles, follow-up date preservation, other booking phrasings and negation.

Verification: local suite 18 passed / 1 Linux-only skip; both Itzik HTTP cases
passed on Polygon's Linux build. The live browser check exercised the exact
screenshot request as manager and as worker, then the short `מ 9 עד 13` follow-up.
It observed a role explanation or a confirmation proposal respectively, without
confirming or writing a shift.

### Keep the request through a past-date correction — 2026-11-18

The next screenshot exposed a separate failure: the hourly answer was parsed,
but Sunday in the displayed week was already past. The generic date rejection
then cleared the browser's pending request. The earlier browser check selected
a future week and did not cover this recovery path.

Itzik now reports the exact past date and retained hours. The browser keeps the
request for `past_booking_date`, so `בשבוע הבא` continues it with the same day
and hours. Numeric ranges in Hebrew replies use explicit direction isolation.
Three focused HTTP cases passed locally and on Linux. The live browser replayed
Sunday → `12-16` → past-date explanation → `בשבוע הבא`, receiving a proposal for
2026-11-22, 12:00–16:00. It did not confirm or write a shift.

### PR review corrections — 2026-11-18

The connector's review of `80cb1f6` identified three confirmed defects:

- Pending signup IDs could be reused after another manager emptied the queue,
  allowing an old confirmation to act on a later request. Fresh databases now
  use `AUTOINCREMENT`. On startup, the C runtime transactionally upgrades schema
  100 to 101 before serving HTTP, preserving pending IDs and all request fields.
  It invalidates existing review tokens because their numeric identities may
  already be ambiguous; managers must prepare those confirmations again.
- The worker landing page now shows the current-month receipt form immediately
  below the calendar, followed by today's coworkers, birthdays and events.
  These are the same panels used by the existing navigation shortcuts. Moving
  to another week keeps today's information and the current receipt month.
- CSV import validates the required/optional header set and rejects duplicate,
  missing and unexpected headers before reading account rows, in dry-run and
  write modes, including header-only files.

Before updating an existing native installation, retain a backup using the old
binary's `--backup` command or `scripts/manage.sh backup`. Schema-100 backups
remain accepted by the new restore command and are upgraded at startup. An old
binary cannot open schema 101; rollback requires the retained schema-100 backup.
The Laravel database is not involved in this native schema update.

Verification: both new data regressions failed against the previous binary /
importer, then passed after the repairs. The local native suite passed **22 tests
with one Linux-only bcrypt skip**. Coverage includes stale approval/deletion by
two managers, version-100 upgrade with pending data and repeated startup, and
invalid CSV headers with no account writes. A disposable local Chrome session
verified the worker panel order at 1440px and 390px, saving/reloading receipts,
today's date across week navigation, and manager visibility. Screenshots were
inspected. PHP/JavaScript syntax and whitespace checks passed. This follow-up
was tested locally; neither Polygon nor the Enter installation was updated.
