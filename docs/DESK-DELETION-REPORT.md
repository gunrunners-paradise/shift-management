# Permanent desk deletion — 2026-10-22

## Authorized behavior

The user explicitly requested permanent desk deletion **instead of deactivation**, then confirmed that **all the desk's reservations, including past history, should also be deleted**. The manager's desk panel now offers `מחיקת העמדה`, with a warning, acknowledgement checkbox and delete button. No name, username or password entry is required.

Deletion removes the desk and its upcoming, running, completed and canceled reservations. Active desks and legacy inactive desks can both be explicitly deleted. Other desks and their reservations, user accounts, shifts, receipts and shared calendar-date locks remain intact. Removing a worker's active reservation this way releases their daily desk slot, allowing a new reservation on another desk under the normal booking rules. A new desk may reuse the deleted name with a new ID.

There is no desk deactivation or reactivation action. Existing desks are not automatically purged, and no live desk or reservation was removed during implementation. Stage 6 remains delivered for review; Stage 7 remains locked. The calendar → receipts → other information order and the five HTML reference files remain unchanged.

## Changed files and request path

| File | Purpose |
|---|---|
| `routes/web.php` | Replaces the deactivation endpoint with manager-only `admin.desks.destroy`: `DELETE /admin/desks/{desk}`. |
| `app/Http/Requests/DeleteDeskRequest.php` | Requires an active manager and accepted acknowledgement; derives the failed inline form ID from the bound route. Replaces the removed `DeactivateDeskRequest.php`. |
| `app/Http/Controllers/Admin/DeskController.php` | `destroy()` coordinates deletion and redirects to the desk panel with Hebrew feedback. |
| `app/Services/DeskManagementService.php` | `delete()` locks the desk, removes all its reservations and deletes the desk inside the existing manager transaction. Replaces `deactivate()`. |
| `app/Services/DeskAvailabilityService.php` | Omits stale history whose desk disappeared between the reservation query and eager loading, before choosing an active reservation or rendering. |
| `app/Http/Requests/DeskPageRequest.php` | Documents safe fallback for a saved link naming a deleted desk. |
| `app/Models/Desk.php` | Documents retained legacy inactive-state fields. |
| `app/Models/DeskReservation.php`, `app/Services/DeskReservationService.php`, `ManagerTransaction.php`, `DateClosureService.php` | Update comments to the deletion workflow and its locking assumptions. |
| `resources/views/admin/desks/panel.blade.php` | Native permanent-delete controls for active and legacy inactive desks, scoped validation and accessible warnings. |
| `public/css/desks.css` | Renames the scoped confirmation-form selector for deletion. |
| `tests/Feature/DeskAdministrationTest.php`, `DeskConcurrencyTest.php`, `CsrfProtectionTest.php`, `DeskPageTest.php` | Replace obsolete deactivation expectations and cover permanent deletion, authorization, validation, rollback, stale reads/requests and concurrency. |
| `tests/Feature/PermanentDeskDeletionConcurrencyTest.php` | Eight deterministic MariaDB races cover both desk-lock orders for first booking, moves into/out of the desk and worker cancellation. |
| `tests/Feature/DeskClosureTest.php` | Corrects the history-preservation comment for worker account deactivation. |
| `AGENTS.md`, `ROADMAP.md`, `README.md`, `docs/STAGE5-REPORT.md`, `docs/EXTRA-FEATURES-REPORT.md`, this report | Record the new policy, supersede earlier desk-removal instructions and retain historical verification records. |

Follow `admin.desks.destroy` → `DeleteDeskRequest` → `Admin/DeskController::destroy()` → `DeskManagementService::delete()` → `ManagerTransaction::run()`. The manager gate and fresh manager row check protect authorization while administrative requests serialize. The service locks the current `Desk` row with `FOR UPDATE`, deletes its `DeskReservation` rows, then deletes the desk. The redirect renders `admin/users/index.blade.php` and its included `admin/desks/panel.blade.php`. Native `<details>`, CSRF-protected forms and the shared CSS work without feature JavaScript.

## Transaction and concurrency behavior

Workers lock their account, the permanent date, ascending desk IDs, then reservation rows. Deletion takes the shared manager gate, the acting manager row and the target desk lock, then deletes reservation rows. It never takes worker/date locks afterward, avoiding the inverse of worker booking order. The manager gate serializes it with date blocking, worker deactivation and account deletion.

If booking commits first, deletion removes that newly created reservation too. If deletion commits first, a waiting booking sees the missing desk and fails normally. Stale edit/cancel service calls also recheck the missing desk; already deleted route-bound resources return 404. If an edit away to another desk commits first, its replacement on that other desk remains. A repeated deletion cannot recreate the old desk.

Restrictive foreign keys remain enabled. Related reservations are explicitly deleted first; a failure during either deletion rolls back the entire transaction. Permanent `calendar_dates` records remain available for future booking/blocking locks. There is no schema change or implicit database cascade.

The worker availability page remains advisory. An in-flight page may show a desk read just before deletion, but submitting it still invokes the authoritative locking checks. A refreshed saved desk URL chooses another available desk. The history reader also handles deletion between Eloquent's separate reservation and desk queries, avoiding a null-relation rendering error. Removing the final desk leaves a valid empty page and does not reseed desks.

## Migration and commands

No migration, dependency, environment change, background process or new JavaScript is required. Existing `is_active`, `deactivated_at` and `deactivated_by` columns remain for compatibility with desks previously deactivated; no current manager action creates that state. Their presence does not make permanent deletion a soft delete. Existing inactive records remain until a manager explicitly deletes them.

## Actual verification

| Check | Result |
|---|---|
| `php artisan test --compact --stop-on-failure` | **464 passed, 2742 assertions, 74.68 seconds; no failures or skips.** |
| Deletion and preservation | Covered all reservation ages/states, active/legacy inactive desks, unrelated data/date-lock retention, freed daily slot, name reuse and final-desk empty pages. |
| Transaction/race checks | Covered rollback after the parent DELETE, manager authorization, stale HTTP/service requests, eight deterministic booking/edit/cancel races, and existing mixed block/account/desk races against MariaDB. |
| Native UI/security checks | Covered required acknowledgement, no typed credentials, scoped Hebrew validation, CSRF missing/wrong/valid tokens and removed deactivation endpoint. |
| Worker page checks | Covered a saved deleted-desk link and deletion between reservation and eager-loaded desk reads. |
| `vendor/bin/pint --test` | Passed. |
| `php artisan view:cache` | All Blade templates compiled. |
| `php artisan route:list --path=admin/desks` | Only add POST, rename PATCH and permanent-delete DELETE; no deactivation route. |
| `git diff --check` and documentation links | Passed. |
| Source references/live data | Five HTML references untouched; no live account, desk or reservation changed. |

The first full run stopped with 144 passes, one failure and 319 pending scenarios (918 assertions, 13.81 seconds). The failing test supplied another desk's inline form ID: validation updated the FormRequest copy, while Laravel flashed the original request. `DeleteDeskRequest::prepareForValidation()` now sets the route-derived display ID in both copies so the correct form reopens. The final full run above passed. No known automated failure remains.

## Manual review

1. In `/admin`, add a disposable desk and optionally rename it. In an independent worker window, reserve that desk on an allowed future date. The worker may also have a shift and current-month receipts saved.
2. In the manager desk panel, expand `מחיקת העמדה`. Confirm the warning includes upcoming, in-progress, past and canceled bookings, and requires only acknowledgement. Submit deletion.
3. Refresh both pages. The desk and its reservation history should disappear, while the worker account, shift, receipt choice and other desks remain. The worker can reserve another desk for that date. A saved link to the deleted desk should load safely.
4. Check the native confirmation, validation feedback, keyboard navigation and mobile RTL layout with JavaScript disabled. Use an existing legacy inactive desk only if its permanent removal is intended; it has the same delete control.

Past-history removal, rollback, final-desk deletion and competing requests are verified using disposable database fixtures rather than changes to real operational data. Owner browser acceptance remains pending; no Raspberry Pi deployment or new browser screenshot is claimed by this report.
