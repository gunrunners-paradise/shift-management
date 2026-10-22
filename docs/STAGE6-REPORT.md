# Stage 6 — Manager CSV download — 2026-10-22

## Scope and confirmed decisions

The user explicitly authorized Stage 6 on 2026-10-22. Managers now have a compact `דוח ימי עבודה — CSV` section and `הורדת CSV` quick action on the administration page. A CSRF-protected POST with a valid inclusive date range is the only report trigger. Opening the dashboard or changing input values does not generate a report. There is no preview table, scheduled task, recurring export, queue job, email delivery or saved report.

The user confirmed both open Stage 6 policies:

- Include **every retained worker**, active or inactive, including workers with zero qualifying days. Zero-day rows contain count `0` and an empty date cell. Manager accounts are excluded.
- Allow start/end dates **only through actual today in `Asia/Jerusalem`**. Reject future dates rather than silently cutting off a requested range. Today's scheduled shifts count regardless of their time; this reports planned workdays, not verified presence or paid hours.

Each non-canceled shift date counts once, regardless of which of the five approved periods it uses. Canceled and replaced history does not add days. Desk reservations do not count. Deactivation preserves historical days; explicit permanent account deletion removes its account/history from subsequent exports. The CSV date range is independent of the manager's event/block lists or selected receipt month. The later visibility follow-up shows current and future months in those lists while hiding past months; it does not limit historical CSV exports.

The exact columns are `שם העובד`, `מספר ימי עבודה`, `תאריכי עבודה`. Dates use chronological `DD/MM/YYYY` values joined by comma and space in one quoted cell. UTF-8 BOM, CRLF, escaped double quotes and consistently quoted cells support spreadsheet import.

Stage 7, archive pages, manager booking overrides and other unapproved features remain deferred. The worker calendar still follows calendar → receipts → other information. All five source HTML references and the original logo remain read-only.

## Changed files and learning path

| File | Purpose |
|---|---|
| `routes/web.php` | Adds manager-only POST `admin.reports.workdays` at `/admin/reports/workdays`. |
| `app/Http/Requests/ExportWorkdaysRequest.php` | Authorizes an active manager and validates canonical, ordered dates through actual Israel today; safely rejects malformed arrays. |
| `app/Http/Controllers/Admin/WorkdayReportController.php` | `__invoke()` coordinates validated input, preparation and streamed response/resource cleanup. |
| `app/Services/WorkdayCsvReportService.php` | `prepare()` rechecks current authority, reads the worker/shift snapshot and creates this request's temporary CSV stream. |
| `resources/views/admin/reports/panel.blade.php` | Native date-range form and short Hebrew explanation, with current-month-through-today defaults. |
| `resources/views/admin/users/index.blade.php` | Adds report stylesheet, in-page quick action and report section. |
| `public/css/reports.css` | Scoped compact desktop form with stacked mobile fields and button. |
| `tests/Feature/WorkdayCsvReportTest.php` | Counts, dates, history, zero-day rows, long lists, CSV encoding/escaping/formula prefixes, authorization, validation and explicit-only generation. |
| `tests/Feature/WorkdayCsvConcurrencyTest.php` | Independent MariaDB processes race preparation against acting-manager deactivation. |
| `tests/Feature/CsrfProtectionTest.php` | Missing, incorrect and valid CSRF coverage for the export endpoint. |
| `tests/Feature/AccountManagementTest.php` | Updates the old stage-specific expectation now that the report section is implemented. |
| `AGENTS.md`, `ROADMAP.md`, `README.md`, `docs/DEPLOYMENT.md`, `docs/STAGE5-REPORT.md` | Confirmed policies, stage authorization, review steps, deployment notes and historical progression link. |

Follow `admin/reports/panel.blade.php` → `admin.reports.workdays` → `ExportWorkdaysRequest` → `Admin/WorkdayReportController::__invoke()` → `WorkdayCsvReportService::prepare()`. The service returns a rewound temporary stream. The controller builds a download response and its callback transfers and closes that stream. There is no feature JavaScript and no new model/table/migration.

The service's first database read locks only the requesting manager and checks current activity, manager role and session version. This prevents stale authority from being used after waiting for another account action. Generation finishes before any HTTP download headers, so authorization or preparation failures remain genuine error responses rather than partial successful downloads. The database transaction ends before transfer to a slow browser; no global manager advisory lock, worker lock or date lock is held during transfer.

One joined InnoDB SELECT reads retained worker identities and qualifying shift dates together. Conditions for the date range and non-canceled state are in the LEFT JOIN so zero-day workers remain present. Sorting by name, stable worker ID and date keeps same-name accounts separate and dates chronological. The existing unique active-worker/date constraint prevents duplicate counts. No `GROUP_CONCAT` limit can truncate long date lists.

The writer quotes every cell, including a single date or an empty list, doubles embedded quotes and uses CRLF. Potential formula/control prefixes in worker names receive a leading apostrophe, including prefixes after Unicode whitespace/format marks and fullwidth operator variants. This follows the text-prefix and quoting approach described by [OWASP's CSV injection guidance](https://community.owasp.org/attacks/CSV_Injection). Spreadsheet import/save behavior varies, so actual Excel acceptance remains a manual check; this does not claim universal safety after editing and re-saving in every spreadsheet program. PHP's [CSV documentation](https://www.php.net/manual/en/function.fputcsv.php) explains the ordinary quoting/escape behavior; a small explicit writer guarantees the required enclosure of every date-list cell.

## Runtime and operational limits

No migration, Composer package, new environment variable, storage link or background process is needed. Existing private environment files and credentials are unchanged. The endpoint performs no business-data writes or account changes.

`php://temp/maxmemory:2097152` holds small output in memory and temporarily spills larger output to system temporary storage. The stream is closed after transfer or preparation failure, removing its temporary backing file. This is per-request buffering, not a retained report. The single Query Builder cursor avoids Eloquent model hydration, but PDO still buffers the selected database result. Very large historical ranges therefore consume memory proportional to their selected rows; realistic device profiling remains for Stage 7.

## Actual verification

| Check | Actual result |
|---|---|
| Full MariaDB suite: `php artisan test --compact --stop-on-failure` | **442 passed, 2626 assertions, 94.18 seconds; no failures or skips.** |
| Focused CSV/account/CSRF run after validation fix | **98 passed, 441 assertions, 3.11 seconds.** The two subsequently added concurrency scenarios are included in the final full run above. |
| Stage 6 coverage | 56 new scenarios, including all five periods, zero/inactive/deleted workers, 100-date cells exceeding 1024 bytes, quoting, formula/control prefixes, malformed inputs, Israel date boundaries, explicit-only generation and concurrent manager deactivation. |
| `vendor/bin/pint --test` | Passed across the application. |
| `php artisan view:cache` | All Blade templates compiled successfully. |
| `php artisan route:list --path=admin/reports` | One intended POST-only report route registered. |
| `git diff --check` | Passed. |
| Reference checksums | All five HTML files and the original logo unchanged. |
| Local HTTP smoke checks | `/login` and `/css/reports.css` both returned HTTP 200. |
| Migration/data changes | No migration required; no live account or business-record changes performed. |

The first focused run caught a real malformed-sibling-date validation issue: Laravel's cross-field comparison attempted to parse an array as a date. That attempt had two failures and 89 passes. The request now adds that comparison only for a string start value and lets the start field's own validation reject arrays. The regression scenarios remain intact, and both the final focused and full runs above passed. No known failing automated check remains.

## Manual acceptance

1. Sign in as manager and use `הורדת CSV`. Choose a known historical date range through today and download. Open it in the intended spreadsheet software and verify Hebrew headers and all three columns.
2. Compare a worker's non-canceled dates with the result. Confirm different period lengths each count one day, dates are chronological in one cell, and canceled/replaced rows add no day. Check an inactive worker with completed history and a worker with zero days.
3. Submit a reversed, empty or future range and check Hebrew validation. Confirm no report is produced merely by opening the dashboard. Repeat the native form with JavaScript disabled and at narrow mobile width.
4. Verify workers cannot call the manager report endpoint and that existing event/block lists, receipts, desk controls, account reactivation/deletion and the worker calendar layout remain intact.

No real operator credentials or application records are needed for the automated cases. Actual spreadsheet opening, owner browser acceptance and Raspberry Pi deployment remain pending. Stop after Stage 6 for the owner's review; Stage 7 requires explicit authorization.
