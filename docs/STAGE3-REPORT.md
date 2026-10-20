# Stage 3 delivery for review — 2026-10-20

The user explicitly authorized Stage 3, required all supplemental calendar information below the grid, and confirmed February 28 as the celebration date for February 29 birthdays in non-leap years. These decisions are recorded in `AGENTS.md` and `ROADMAP.md`. Stage 3 is supplied for review; Stage 4–7 remain locked.

Progression note: the user's explicit request to start Stage 4 on 2026-10-20 authorized the next stage. This report preserves the Stage 3 delivery record; current scope and results are in [the Stage 4 report](STAGE4-REPORT.md). This does not claim the owner independently performed every manual acceptance step below.

## Delivered scope

- Both workers and managers have an own-profile page through the shared account menu. It follows the profile reference's identity sidebar and two separate forms, using the existing shared layout/components. Users can update their name, gender, birth date and photo. Username and role remain read-only; submitted identity, activation, role and storage-path fields are rejected.
- The password form verifies the current password under the user row lock. New passwords follow the existing 12-character minimum, 72-byte maximum and confirmation rules. Passwords remain securely hashed; no plaintext password is flashed back into a form. A successful change rotates the current session/token, preserves current access and revokes other database sessions. Laravel's `auth.session` middleware checks the password hash on later requests, covering old sessions rewritten by overlapping requests. Login also records the verified hash in session immediately.
- JPG, PNG and WebP photos are validated using server-detected content, file size and dimensions: at most 2048KB and 2048 × 2048 pixels. Random filenames are stored privately under `storage/app/private/avatars/`; submitted filenames never become storage paths. Uploaded SVG/PHP files are rejected. Authenticated image delivery permits the viewer's own avatar or an active worker's team avatar, with explicit image MIME and `nosniff` headers. Missing/invalid image paths fall back to the supplied default avatar. A Hebrew 413 page handles requests exceeding PHP's POST-size limit before form validation.
- Photo replacement commits the new database reference before removing the old file. A failed/stale-account update removes its unused staged file. Cleanup after a successful commit cannot remove the newly referenced image if old-file removal fails. A file handle opened for delivery remains usable if a concurrent replacement removes its filename. A path-free photo fingerprint in the image URL lets polling notice a photo-only change without exposing the private filename.
- The worker calendar renders real birthdays and today's planned coworkers **below** its existing grid/footer. The five-row calendar, navigation, legend and selection behavior are retained. Birthdays include only active workers matching the actual day/month, with multiple people supported and no age/year disclosure. February 29 uses February 28 only in non-leap years.
- The coworker list includes the signed-in worker when scheduled, excludes managers/inactive workers/canceled shifts, and displays the correct label and hours from the existing five-period configuration. It describes planned staffing, not physical presence. Browsing another calendar week does not change these actual-today panels.
- Initial Blade panels work without JavaScript. A small worker-only JSON endpoint returns the same limited display fields. Vanilla JS refreshes only the supplemental panels about every 30 seconds, pauses in hidden tabs and refreshes on return/focus. Network errors preserve the previous list; authentication failure stops polling and clears team details.

Deferred: manager editing of other users' profiles, events/holidays, monthly receipts, desks/reservations, CSV and deployment acceptance. Future supplemental widgets must also be below the grid when their stages are authorized. No future-stage controls, tables, routes, sample birthdays or localStorage records were added.

## Changed files

| Area | Files |
| --- | --- |
| Profile controllers/validation | New `app/Http/Controllers/ProfileController.php`, `AvatarController.php`; `app/Http/Requests/UpdateProfileRequest.php`, `UpdatePasswordRequest.php` |
| Profile persistence/files | New `app/Services/ProfileService.php`, `AvatarStorage.php`; updated `app/Models/User.php` with `avatarUrl()` |
| Calendar information | New `app/Services/TodayInformationService.php`, `app/Http/Controllers/TodayInformationController.php`; updated `ShiftCalendarController.php` |
| Routing/session security | `routes/web.php`, `app/Http/Controllers/Auth/AuthenticatedSessionController.php` |
| Views/assets | New `resources/views/profile/edit.blade.php`, `worker/today.blade.php`, `errors/413.blade.php`, `public/css/profile.css`, `today.css`, `public/js/today.js`; updated `components/header.blade.php`, `worker/home.blade.php` |
| Hebrew validation | `lang/he/validation.php` |
| New tests | `tests/Feature/ProfileTest.php`, `ProfileAvatarTest.php`, `ProfileConcurrencyTest.php`, `WorkerTodayInformationTest.php` |
| Existing test adaptations | `tests/Feature/CsrfProtectionTest.php`, `ShiftCalendarTest.php`, `AuthenticationTest.php`, `DateClosureTest.php` |
| Documentation | `AGENTS.md`, `ROADMAP.md`, `README.md`, `docs/DEPLOYMENT.md`, a progression note in `docs/STAGE2-REPORT.md`, this report |

No migration is required: `users` already contains the profile and photo-path columns. Existing account and scheduling records remain in place. The private environment files and 30-day session lifetime were preserved. All five root HTML references and the original/public logo were verified unchanged.

## Read the request end to end

**Personal details:** `profile.edit` (`GET /profile`) → authentication, active-account and session-hash checks → `ProfileController::edit()` → `resources/views/profile/edit.blade.php`, with the shared authenticated header. Its CSRF-protected multipart form submits to `profile.update` (`PATCH /profile`) → `UpdateProfileRequest` → `ProfileController::update()` → `ProfileService::update()`. The service explicitly assigns allowed fields to the session owner's locked `User` record and optionally calls `AvatarStorage::store()`. After commit it removes the prior unique avatar file. The controller refreshes the authenticated user and redirects to the profile. `profile.css` supplies the responsive sidebar/forms; shared `app.js` enhances password visibility and menus.

**Password:** the separate form submits to `profile.password.update` (`PUT /profile/password`) → `UpdatePasswordRequest` → `ProfileController::updatePassword()` → `ProfileService::changePassword()`. The service locks the user, rechecks activity and the current hash, assigns the new password through the model's hashed cast, rotates the remember token and deletes other database sessions in one transaction. The controller refreshes the authenticated user and regenerates the current session. `auth.session` records the refreshed hash on the response and rejects old hashes on subsequent requests.

**Photos:** `User::avatarUrl()` returns the default public SVG or named `avatars.show` URL. `GET /avatars/{user}` → authenticated middleware → `AvatarController::show()` checks own/active-worker access → `AvatarStorage::response()` verifies a generated path and whitelisted image MIME, opens a private file stream and returns image bytes. No arbitrary filename is accepted in the URL, and no public storage symlink is used. Deactivation still uses the same user row lock as profile writes, preventing a stale profile request from reactivating an account.

**Calendar panels:** `GET /worker` → `ShiftCalendarController::index()` → `TodayInformationService::forToday()` → two filtered reads for birthdays and non-canceled planned staffing on Israel's actual date. `worker/home.blade.php` includes `worker/today.blade.php` below the table. The service returns explicit names/avatar URLs and configured period labels/hours; it does not serialize full users or birth dates. `worker.today` (`GET /worker/today`) → worker-only middleware → `TodayInformationController::__invoke()` → the same service → JSON. `public/js/today.js::initializeTodayInformation()` manages the polling lifecycle and calls `renderTodayInformation()` using DOM text nodes; the calendar is never regenerated by that script.

## Verification

Commands used:

```bash
php artisan test --compact
vendor/bin/pint --test
composer validate --strict
composer check-platform-reqs
php artisan view:cache
php artisan route:list --except-vendor
```

The suite uses the guarded, isolated MariaDB test schema and fake storage disks for uploaded test files. Real database commits are used for avatar cleanup and concurrency tests. New race scenarios use independent committed MariaDB connections: two password changes competing with the same old password, and a profile update racing worker deactivation. Existing scheduling/account races remain in the full suite.

The first full run had 166 passes and nine failures. Those failures came from tests switching authenticated users within one simulated session while retaining the previous user's password-hash marker. Four test methods now explicitly flush that session before switching independent actors. Production session checks and the stale-hash rejection test remain intact.

| Check | Actual result |
| --- | --- |
| `php artisan test --compact` | **176 passed, 1119 assertions, no failures/skips**, 19.02 seconds, against MariaDB 10.11.14, including the Hebrew oversized-request error |
| Final photo/roster regression run | **26 passed, 185 assertions**, after adding the image URL fingerprint so a photo-only replacement appears on refresh |
| New process races | Passed: competing password changes accept exactly one old-password use; profile update versus deactivation never reactivates or elevates the worker |
| Existing scheduling/account races | Passed as part of the full suite |
| Upload/session/date security tests | Passed: actual MIME/spoof rejection, size/dimensions, private delivery, file cleanup and cleanup-after-commit failure, CSRF, self ownership, stale password-hash sessions, birthday privacy/leap-day/Israel midnight, five-period actual-today roster and below-grid placement |
| `vendor/bin/pint --test` | Passed |
| `composer validate --strict` | Passed |
| `composer check-platform-reqs` | Passed on PHP 8.3.6; GD is also available for generated image fixtures |
| `php artisan view:cache` | Passed |
| `php artisan route:list --except-vendor` | Seventeen intended application routes, with no Stage 4+ routes |
| Live local HTTP smoke check | `/login` returned 200 after Stage 3 changes |
| Browser polling checks | **10/10 passed** with stubbed fetch responses: 30-second scheduling, successful updates, text-safe names, unchanged calendar, hidden pause/visible refresh, network-failure retention/retry, 401 clearing and stop |
| Reference integrity | Five root HTML references and original/public logo unchanged |

Browser previews use temporary unsaved users and Blade data, without creating real application accounts, uploading real profile photos or publishing preview routes. Six screenshots were checked: profiles for both roles at desktop/mobile widths and calendar at desktop/mobile widths. Calendar screenshots show multiple birthdays and all five coworker periods below the unchanged grid, with correctly ordered numeric time ranges. The temporary polling harness exercises the actual JS against stubbed responses; MariaDB feature tests independently verify the real endpoint. Owner acceptance remains pending.

## Manual review and remaining limits

Follow `README.md` under “Review Stage 3”: edit each role's own profile, upload/replace a photo, reject an invalid upload, verify wrong/correct current-password behavior with two browser sessions, and check real birthday/coworker panels below the calendar. Use two workers to verify the roughly 30-second refresh after selecting/canceling today's shift. Test desktop/mobile RTL, keyboard menus/password visibility and native forms with JavaScript disabled. The automated date tests cover leap years and Israel midnight without modifying real user birthdays.

Local runtime commands remain `bash scripts/local-database.sh start` and `php artisan serve --host=127.0.0.1 --port=8000`. There is no new migration or `storage:link` command for this stage. Application photo storage needs write permission and must be included in confidential backups alongside MariaDB and the original environment key. The deployment guide documents the upload limits, private storage and restore checks.

No known failing check remains. User acceptance and real Raspberry Pi/Apache/HTTPS/backup-restore verification remain outstanding. Stop after this Stage 3 delivery; proceed to Stage 4 only on explicit authorization.
