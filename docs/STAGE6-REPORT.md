# Stage 6 — Manager CSV download — 2026-10-22

## Scope and confirmed decisions

The user explicitly authorized Stage 6 on 2026-10-22. Managers now have a compact `דוח ימי עבודה — CSV` section and `הורדת CSV` quick action on the administration page. A CSRF-protected POST with a valid inclusive date range is the only report trigger. Opening the dashboard or changing input values does not generate a report. There is no preview table, scheduled task, recurring export, queue job, email delivery or saved report.

The user confirmed both open Stage 6 policies:

- Include **every retained worker**, active or inactive, including workers with zero qualifying days. Zero-day rows contain count `0` and an empty date cell. Manager accounts are excluded.
- Allow start/end dates **only through actual today in `Asia/Jerusalem`**. Reject future dates rather than silently cutting off a requested range. Today's scheduled shifts count regardless of their time; this reports planned workdays, not verified presence or paid hours.

Each non-canceled shift date counts once, regardless of which of the five approved periods it uses. Canceled and replaced history does not add days. Desk reservations do not count. Deactivation preserves historical days; explicit permanent account deletion removes its account/history from subsequent exports. The CSV date range is independent of the manager's event/block lists or selected receipt month. The later visibility follow-up shows current and future months in those lists while hiding past months; it does not limit historical CSV exports.

The current columns, after the approved 2026-10-25 follow-up below, are `שם העובד`, `מספר ימי עבודה`, `תאריכי עבודה`, `קבלות`. Dates use chronological `DD/MM/YYYY` values joined by comma and space in one quoted cell. `קבלות` is `יש` or `אין` for the manager's independently selected receipt month; an unchecked or missing receipt record means `אין`. UTF-8 BOM, CRLF, escaped double quotes and consistently quoted cells support spreadsheet import. The original 2026-10-22 delivery had the first three columns; its verification results below remain historical.

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

## Original delivery verification — 2026-10-22

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

1. Sign in as manager and use `הורדת CSV`. Choose a known historical date range through today and a `חודש קבלות`, then download. Open it in the intended spreadsheet software and verify Hebrew headers and all four columns.
2. Compare a worker's non-canceled dates with the result. Confirm different period lengths each count one day, dates are chronological in one cell, and canceled/replaced rows add no day. Check an inactive worker with completed history and a worker with zero days.
3. Compare `קבלות` with known checked, unchecked and absent records for the selected month, including a zero-day or inactive worker. Export the same workday range for another receipt month: only receipt status should change. Confirm the separate dashboard receipt-month picker does not change this form's selected month.
4. Submit a reversed, empty or future workday range, or omit the receipt month, and check Hebrew validation. A valid future receipt month is readable and normally has `אין` where no record exists; it does not allow exporting future workdays. Confirm no report is produced merely by opening the dashboard. Repeat the native form with JavaScript disabled and at narrow mobile width.
5. Verify workers cannot call the manager report endpoint and that existing event/block lists, receipts, desk controls, account reactivation/deletion and the worker calendar layout remain intact.

No real operator credentials or application records are needed for the automated cases. Actual spreadsheet opening, owner browser acceptance and Raspberry Pi deployment remain pending. Stop after Stage 6 for the owner's review; Stage 7 requires explicit authorization.

## Follow-up: receipt status in the CSV — 2026-10-25

The user requested a fourth column named `קבלות`, containing exactly `יש` or `אין`, and confirmed that the manager should select which receipt month to use. The CSV form therefore requires an explicit `חודש קבלות` value, initially the actual current month in `Asia/Jerusalem`. This selection is independent of the inclusive workday range and the separate monthly receipt list. It supports valid past, current or future months for reading; the existing restriction on workers changing only the actual current month remains in force.

For each retained worker, a true `monthly_receipts.has_receipts` value for the selected month produces `יש`. A false value or no matching row produces `אין`, including for inactive and zero-day workers. Receipt status does not add days, require a shift in that month or aggregate multiple months. Workday dates still cannot exceed actual Israel today. The original three workday columns retain their order and meaning; `קבלות` is appended as the fourth column.

### Follow-up files and request flow

| File | Follow-up change |
|---|---|
| `resources/views/admin/reports/panel.blade.php` | Adds the required native `receipt_month` month input, labeled `חודש קבלות`, with an actual-current-month default and validation redisplay. |
| `app/Http/Requests/ExportWorkdaysRequest.php` | Validates the required canonical `YYYY-MM` month and supplies its Hebrew attribute label; existing date and manager rules remain. |
| `app/Http/Controllers/Admin/WorkdayReportController.php` | Passes the validated receipt month to `prepare()` with the workday bounds and includes it in the download filename. |
| `app/Services/WorkdayCsvReportService.php` | Appends the header/status and adds a month-constrained LEFT JOIN to `monthly_receipts` in the existing snapshot query. |
| `tests/Feature/WorkdayCsvReportTest.php` | Updates the four-column contract and covers receipt states, month selection and validation, zero-day/inactive workers and unchanged workday counts. |
| `tests/Feature/WorkdayCsvConcurrencyTest.php`, `tests/Feature/CsrfProtectionTest.php` | Supply the required month in existing valid download scenarios. |
| `AGENTS.md`, `ROADMAP.md`, `README.md`, `docs/STAGE6-REPORT.md`, `docs/EXTRA-FEATURES-REPORT.md` | Record the confirmed receipt-month choice, current CSV contract, code path and manual checks. |

The existing `POST /admin/reports/workdays` route still calls `ExportWorkdaysRequest` → `WorkdayReportController::__invoke()` → `WorkdayCsvReportService::prepare($actor, $startDate, $endDate, $receiptMonth)`. The service converts the selected month to its first-day database key and reads each worker's receipt boolean alongside qualifying shifts in one InnoDB statement. The unique worker/month constraint prevents the added join from duplicating shift rows. Missing receipts remain a LEFT JOIN result so neither zero-day nor unchecked workers disappear. The existing requesting-manager lock, authority check, CSV quoting/formula-prefix protection and temporary-stream cleanup remain in place.

No database row is changed by downloading. This follow-up requires no migration, new route, model, CSS, JavaScript, dependency or scheduled task. The existing report form styles accommodate the additional field. Spreadsheet and owner desktop/mobile acceptance remain manual checks; Stage 7 remains locked pending explicit approval.

### Follow-up verification

The 2026-10-22 results above describe the original delivery. The receipt-column follow-up was verified separately on 2026-10-25:

| Check | Actual result |
|---|---|
| Full MariaDB suite: `php artisan test --compact --stop-on-failure --stop-on-error` | **482 passed, 2877 assertions, 77.62 seconds; no failures or skips.** |
| Receipt coverage | Checked/unchecked/missing states; selected past/future months independent of multi-month workday ranges and the dashboard filter; zero-day/inactive/same-name workers; unchanged day counts; required-month validation and safe redisplay; actual Israel month default; filename context; existing CSRF and manager-deactivation races. |
| `vendor/bin/pint --test` | Passed across the application. |
| `php artisan view:cache` | All Blade templates compiled successfully. |
| `php artisan route:list --path=admin/reports` | The existing POST-only report route remains the sole report endpoint. |
| `git diff --check` | Passed. |
| References and runtime changes | All five HTML references and the logo remain unchanged. No migration, secret/configuration change or live business-record update was required. |

Initial test attempts could not connect to the stopped local MariaDB socket and completed no assertions. The stale PID referred to an unrelated system process; its file was preserved under `.local/mariadb/` without signaling that process or resetting data. This tool environment also stopped a detached server between commands, so the existing persistent instance was held open in a foreground session for the passing run. Tests used only the guarded, separate `_test` database. Actual spreadsheet opening and owner desktop/mobile acceptance remain pending using the manual steps above.
