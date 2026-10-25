# Extra features and follow-up report — 2026-10-22

This report collects the user-approved additions, corrections and local setup work requested between numbered stages. Existing detailed reports are linked below; the session policy and manager-list visibility receive fuller coverage here. The descriptions reflect the latest confirmed behavior.

This report records approved work; creating the report itself changes no application behavior, account, database record or stage approval. The later permanent desk-deletion implementation has its own linked report. Stage 6 remains delivered for review; Stage 7 remains locked. Earlier reports describe their delivery dates, including stage restrictions that were subsequently superseded by explicit authorization recorded in [ROADMAP.md](../ROADMAP.md).

## Coverage and existing reports

| Follow-up | Current result | Existing documentation |
|---|---|---|
| Permanent account deletion | Managers can permanently delete inactive accounts and their owned shifts, desk reservations, receipts, sessions and photo. Shared records and other workers' history remain. | [Account deletion report](ACCOUNT-DELETION-REPORT.md), including the one-time authorized local cleanup. |
| Simpler deletion confirmation | An acknowledgement checkbox and delete button are sufficient; no typed username or password is required. | [Deletion confirmation correction](ACCOUNT-DELETION-REPORT.md#confirmation-simplification--2026-10-22). |
| Simpler deletion wording | Account/desk controls use `מחיקה`, `מחיקת החשבון` and `מחיקת העמדה`. The affected records and inability to undo deletion remain explicit; deletion behavior is unchanged. | Confirmed wording in [AGENTS.md](../AGENTS.md) and [ROADMAP.md](../ROADMAP.md); current labels also appear in the linked deletion reports. |
| Account reactivation | Managers can reactivate an inactive worker or manager with the same identity and password. A fresh login is required; canceled bookings remain canceled. | [Account reactivation report](ACCOUNT-REACTIVATION-REPORT.md). |
| Permanent desk deletion | Replaces desk deactivation. Managers can delete an active or legacy inactive desk and all its reservations, including completed and canceled history. Other desks and worker accounts/shifts/receipts remain. | [Desk deletion report](DESK-DELETION-REPORT.md), including reservation cleanup, transaction locks and stale-request checks. |
| CSV receipt status and month selection | The manager chooses a receipt month separately from the workday range. The fourth column `קבלות` shows `יש` for a checked box or `אין` for unchecked/missing records, including zero-day and inactive workers. | [Stage 6 receipt-column follow-up](STAGE6-REPORT.md#follow-up-receipt-status-in-the-csv--2026-10-25), including code flow, verification and manual review. |
| Manager event/block list visibility | Show the entire current Israel month and every future month/year. Hide only past months, retaining their records. | Detailed below; policy also appears in [AGENTS.md](../AGENTS.md), [ROADMAP.md](../ROADMAP.md) and [README.md](../README.md#manager-events-and-blocked-dates). |
| Thirty-day login sessions | Sessions allow 43,200 minutes of inactivity, renewed by application requests. Logout and access revocation still apply. | Detailed below; previously summarized in [README.md](../README.md#run-in-this-workspace) and mentioned in the Stage 3/reactivation reports. |
| Calendar information order | Calendar first, receipts immediately below it, then announcements, birthdays and coworkers on desktop and mobile. | [Stage 3 report](STAGE3-REPORT.md) and [Stage 4 report](STAGE4-REPORT.md), including the receipts placement correction. |
| Profile navigation | `הפרופיל שלי` belongs only in the avatar/account dropdown. The hamburger contains role-appropriate site pages. | Stage 3 navigation in [ROADMAP.md](../ROADMAP.md), the [Stage 3 report](STAGE3-REPORT.md), and the current shared [header](../resources/views/components/header.blade.php). |
| Disabled dates disable desk cards | Unavailable dates, including blocked dates, render disabled desk cards with explicit text. Reopening never restores canceled reservations. | [Stage 5 desk-card follow-up](STAGE5-REPORT.md#follow-up-disabled-dates-also-disable-desk-cards). |
| Manager wording | Manager titles use `מנהל`, `מנהלת`, or neutral `מנהל/ת` according to the stored gender. | Wording correction in the [Stage 1 report](STAGE1-REPORT.md); current [User::roleLabel()](../app/Models/User.php). |
| Persistent local database recovery | After the temporary database was lost, the user approved a fresh persistent local database, with separate application/test schemas and documented lifecycle commands. | Recovery note in the [Stage 1 report](STAGE1-REPORT.md) and [prepared-workspace deployment guide](DEPLOYMENT.md#prepared-workspace-database). This was setup repair, not an added product page. |

The in-progress cancellation rules for blocked dates and worker accounts, February 29 birthday policy, current-month-only worker receipts, zero-day CSV rows and exclusion of future CSV dates are already documented in their respective stage reports and the confirmed policies. The original desk-deactivation policy in the Stage 5 report was superseded by the permanent desk-deletion follow-up linked above.

## Manager events, holidays and blocked dates

The final visibility rule uses the **first day of the actual current month in `Asia/Jerusalem`** as its only date cutoff. For example, during October 2026, an October 1 record remains visible even after that day passes; November 2026 and October 2027 records also appear. September 2026 and earlier records are hidden.

Each list retains its existing ten-record pagination and ascending date order. Totals include all matching current/future records, and the events and blocks paginators remain independent. The receipt-report month picker and unrelated month query parameters do not change the cutoff. At the next dashboard load after Israel's month boundary, the previous month's records disappear from these lists while remaining stored.

Future blocks continue to prevent both shifts and desk reservations. Worker announcements still show only actual today's applicable events; broadening the manager's list does not broaden worker announcements. Earlier blocked dates within the current month remain read-only under the existing prohibition on changing past blocks.

### Implementation and reading guide

`GET /admin` (`admin.users.index`) → manager authorization and `AdminDashboardRequest` → [Admin/UserController::index()](../app/Http/Controllers/Admin/UserController.php). The controller calculates `$currentMonthStart`, reads `BlockedDate` and `CalendarEvent` using `date >= $currentMonthStart`, then paginates the filtered queries. There is no upper month boundary and no database mutation in this visibility change.

The existing [blocked-date panel](../resources/views/admin/blocks/panel.blade.php) and [event panel](../resources/views/admin/events/panel.blade.php) render the records, totals, pagination and Hebrew headings/empty messages describing the current month onward. The dashboard includes both panels through `admin/users/index.blade.php`.

The implementation changed the controller, these two panels and [DashboardMonthVisibilityTest.php](../tests/Feature/DashboardMonthVisibilityTest.php); the policy/review documentation was updated in `AGENTS.md`, `ROADMAP.md`, `README.md` and the relevant note in `STAGE6-REPORT.md`. It required no migration, new route, service/model changes, CSS, JavaScript or record deletion.

### Automated evidence and manual review

The ten updated visibility scenarios cover current and future months/years, retained hidden past records, empty lists, sorting and independent pagination across month/year boundaries, receipt/query independence, ordinary/year/leap-February Israel midnight rollover, immediately visible future events/holidays, and future blocks rejecting both worker booking types.

For owner review, open `/admin`, confirm current-month and future-month entries appear, and use the existing pagination when there are more than ten. Check that previous-month entries are absent. Change the receipt-report month and confirm these lists remain unchanged. A disposable future block within the worker booking horizon should remain unavailable on both worker pages. Automated fixtures cover rollover without changing the host clock or real records.

## Thirty-day session policy

The application uses Laravel server sessions. The approved lifetime is **30 days of inactivity**, equivalent to **43,200 minutes**, renewed by application requests. It is not a fixed thirty-day expiry measured from the original login. Authenticated calendar refresh requests also count as application requests; the system does not separately measure keyboard or mouse activity.

The readable default is in [config/session.php](../config/session.php): `lifetime` reads `SESSION_LIFETIME` and defaults to `43200`. The safe deployment example, [.env.example](../.env.example), also sets `SESSION_LIFETIME=43200`. The default `expire_on_close` is false. A deployment's private environment can override these settings; no private environment values or credentials are reproduced in this report.

The login request passes through [LoginRequest::authenticate()](../app/Http/Requests/LoginRequest.php) and [AuthenticatedSessionController::store()](../app/Http/Controllers/Auth/AuthenticatedSessionController.php); Laravel's configured session middleware handles persistence and expiry. Explicit logout invalidates the session. [EnsureAccountIsActive::handle()](../app/Http/Middleware/EnsureAccountIsActive.php) additionally rejects inactive accounts and stale session versions, independent of the lifetime. Reactivation therefore does not make a revoked old login valid again.

This policy adjustment required no migration, new page, separate token system or feature JavaScript. On another installation, set `SESSION_LIFETIME=43200` in its private environment and refresh Laravel's configuration using that installation's existing deployment procedure. Do not replace its environment file or application key.

The default/example values and existing login/revocation flows were checked against the current source for this report. The repository has no dedicated thirty-day expiry-boundary test, and no thirty-day real-time wait is claimed. Existing authentication and reactivation tests cover login, logout and revoked/stale sessions; they do not independently prove this exact timeout duration.

## Verification record and commands

The manager future-month visibility correction was verified **before the initial consolidated documentation update**:

| Check | Recorded result |
|---|---|
| `php artisan test --compact --stop-on-failure` against the dedicated MariaDB test schema | **442 passed, 2627 assertions, 68.74 seconds; no failures/skips.** |
| Initial sandboxed attempt | MariaDB socket access was blocked; zero assertions. The rerun with socket access produced the passing result above. |
| `vendor/bin/pint --test` | Passed. |
| `php artisan view:cache` | Passed. |
| `git diff --check` | Passed. |
| Five HTML references | Untouched by that follow-up. The earlier wording correction is separately recorded in the historical Stage 1 report. |

Feature-specific test results remain in their linked reports; they are historical snapshots, not additional tests to add to a later total. See [the desk deletion report](DESK-DELETION-REPORT.md) for the subsequent implementation and verification. The account deletion and reactivation migrations were applied at their delivery; another installation needs `php artisan migrate` to receive them. Creating this consolidated report requires no migration or runtime command.

The later permanent desk-deletion follow-up passed the complete MariaDB suite: **464 tests, 2742 assertions, 74.68 seconds, no failures/skips**. Its linked report records the changed files, request flow, initial validation-feedback failure and correction, transaction/race checks and manual review steps. It required no migration or live desk cleanup.

The initial documentation-only update added this file and links from `README.md` and `ROADMAP.md`; its local links and whitespace checks passed without rerunning application tests. Later approved implementations are added to the index above and verified separately in their linked reports. Manual browser/spreadsheet acceptance and Raspberry Pi deployment remain outstanding where the individual reports say so.

## Deletion wording correction — 2026-10-22

The user requested familiar Hebrew wording for account and desk deletion. The two admin Blade panels now use `מחיקה`, `מחיקת החשבון` and `מחיקת העמדה`; `DeleteUserRequest`, `DeleteDeskRequest`, the two admin controllers and `AccountDeletionService` use the same simpler wording in validation and status messages. The warning about affected data and inability to undo deletion remains. The README and both deletion reports quote the updated labels; the deleted-worker CSV fixture uses a neutral sample surname. Routes, authorization, acknowledgement requirements and database workflows are unchanged, with no migration or CSS/JavaScript change.

The existing account/desk deletion tests and deleted-account CSV scenario passed **51 tests and 296 assertions in 14.10 seconds**, with no failures/skips. Pint, Blade compilation and whitespace checks passed. No new tests were added for these text-only edits. For manual review, open the manager account/desk deletion controls and confirm the headings, buttons, acknowledgement and validation messages use the simpler terms.

## CSV receipt status — 2026-10-25

The approved CSV follow-up appends `קבלות` after the three workday columns. Its value is exactly `יש` or `אין`, using the manager's required `חודש קבלות` selection in the download form. The initial selection is the actual current Israel month. It is independent of both the workday date range and the separate dashboard receipt-report picker, so a multi-month workday range has one explicit receipt month rather than an inferred or combined status.

A checked record for that worker/month means `יש`; unchecked or missing records mean `אין`. Retained inactive and zero-day workers receive the same two-state treatment. Managers may read any valid receipt month, while workday dates still stop at today and workers still edit only the actual current month's checkbox. The existing POST-only download, authority checks and CSV encoding remain; no migration, database write, new route, CSS or JavaScript is needed. The [dated Stage 6 follow-up](STAGE6-REPORT.md#follow-up-receipt-status-in-the-csv--2026-10-25) records changed files, request flow and manual checks. Earlier totals in this report remain historical and do not describe verification of this addition.

## Deferred work

A separate archive for past events/holidays and blocked dates remains a possible future feature recorded in `AGENTS.md`. No archive page, route, filter or control has been implemented. Stage 7 and other unapproved features remain outside this report's authorized work.
