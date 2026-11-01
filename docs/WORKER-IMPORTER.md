# CSV worker account importer — 2026-11-01

This document records the complete agreed importer specification, operator workflow and delivery report. The user authorized implementation on 2026-11-01 after reviewing the attached requirements and confirming the two format corrections below. Development does not authorize running an import against production or creating real employee accounts.

## Purpose and approved scope

Add a reusable synchronous Laravel Artisan command for importing approximately 30 employee accounts into an existing MariaDB-backed installation, including the Raspberry Pi. The command runs locally in the server terminal, uses the installation's configured database connection, and requires no HTTP request or additional Composer package.

```bash
php artisan app:import-workers storage/app/private/workers.csv
php artisan app:import-workers storage/app/private/workers.csv --dry-run
```

The first argument is a file path. Relative paths resolve from the Laravel project root; absolute paths are accepted too. Run Artisan as the installation's normal Linux application operator using its ordinary environment and runtime database account. Existing account records, passwords, activation states and owned history must remain unchanged.

The feature is limited to the command, necessary shared validation/account logic, automated tests and documentation. It adds no frontend, dashboard control, worker form change, login/authentication behavior, schema migration, scheduled job, background worker or external CSV library. Manager-only HTTP account creation retains its existing authorization checks. After the importer was implemented, the user's previously requested GitHub-oriented README reorganization was also completed: the concise overview and setup choices stay in [README.md](../README.md), while all existing detailed acceptance checks and request-to-code paths are preserved in [DEVELOPMENT.md](DEVELOPMENT.md).

## Final CSV format

Use these exact English column names and this documented order when preparing a file with birthdays:

```csv
username,first_name,last_name,gender,birth_date,password
```

For a file without birthdays, omit the entire birthday column:

```csv
username,first_name,last_name,gender,password
```

The five required columns are `username`, `first_name`, `last_name`, `gender` and `password`. The only optional column is `birth_date`. The examples show the recommended order; the reader maps fields by header name, so the same columns may be reordered if every row follows that header. Every row must contain exactly the fields specified by its header. Missing, unexpected or duplicate column names are errors.

| Column | Agreed requirement |
|---|---|
| `username` | Required. Trim surrounding whitespace and normalize to lowercase using the existing application behavior. After normalization: 3–60 characters, starts with an ASCII letter or digit, and otherwise contains only ASCII letters, digits, `.`, `_` or `-`. Existing database usernames are skipped. |
| `first_name` | Required; up to 60 characters. Hebrew and English names are supported. Follow the existing account-input whitespace handling. |
| `last_name` | Required; up to 60 characters. Hebrew and English names are supported. Follow the existing account-input whitespace handling. |
| `gender` | Required; exactly `female`, `male` or `other`, matching the current application choices. |
| `birth_date` | Optional column and optional cell. Blank/missing values become SQL `NULL`. Provided values must be real dates in canonical `YYYY-MM-DD` format and no later than actual today in `Asia/Jerusalem`, using the existing application rules. Workers can add or update this later through their profile. |
| `password` | Required; use the existing account password rules: at least 12 characters and at most 72 UTF-8 bytes. Preserve password content; do not trim, generate or overwrite it. The CSV has no confirmation column. |

The confirmed format **has no `role` column**. The importer assigns `worker` itself, so the CSV cannot create managers or grant additional permissions. It also sets `is_active=true` itself; no activation column is accepted. These rules supersede the attachment's earlier required `role=worker` column and retain its active-by-default requirement.

Files must be UTF-8; an initial UTF-8 BOM is accepted and Hebrew characters must survive unchanged. Use commas as delimiters and double quotes for CSV enclosure. Values containing commas must be quoted; a literal double quote inside a quoted value is doubled. Linux LF and Windows CRLF line endings are accepted. Completely empty lines are ignored. Reject malformed quoting, incorrect field counts and invalid text rather than discarding data or importing a partial interpretation. PHP's built-in CSV handling is used without a third-party package.

## Complete fictional two-worker examples

These people and passwords are fictional documentation examples. Choose different private passwords for real employees and do not use the published examples for production accounts. The importer never creates accounts automatically from this document.

With one known birthday and one blank birthday:

```csv
username,first_name,last_name,gender,birth_date,password
example.david,דוד,כהן,male,1995-04-12,ExampleOnlyDavid2026!A
example.sarah,שרה,לוי,female,,ExampleOnlySarah2026!B
```

Without a birthday column:

```csv
username,first_name,last_name,gender,password
example.david,דוד,כהן,male,ExampleOnlyDavid2026!A
example.sarah,שרה,לוי,female,ExampleOnlySarah2026!B
```

Use one version per file. These two examples describe the same usernames; importing both does not create four accounts. To include a comma in a name, enclose that field in double quotes, for example `"כהן, לוי"`. Save as **CSV UTF-8 with commas**, rather than a spreadsheet workbook or a semicolon-separated regional CSV.

## Validation and duplicate policy

1. Read the private file, check the header and parse every nonempty record. Report relevant row numbers and understandable field/syntax errors without raw record content or password values. Row numbers refer to the record's physical starting line, including when a quoted record spans multiple lines.
2. Apply the existing account validation and username normalization to every row before creating anyone. Provided birthdays use the same validation as account/profile entry. Because CSV supplies a password once, the trusted CLI workflow validates its value without requiring a second confirmation field.
3. Detect duplicate normalized usernames within the CSV. These are fatal validation errors, including case/whitespace variants. Invalid rows abort the complete batch even if another row is valid or the invalid row's username already exists in MariaDB.
4. Check all existing database accounts for each username, including managers and inactive workers. Warn with the username and skip that row; never update its password, role, personal information or active state. This is a successful skip, not a validation error. Repeated imports must not create duplicates.
5. If any header, syntax or data validation fails, create no accounts. Use a failure exit code and report the errors. Do not truncate, reset, modify or delete existing records.
6. Only after whole-file validation succeeds, create all new workers within one database transaction. Respect the unique username constraint and recheck existing names at the write boundary. A failure during creation rolls back every new account in this batch rather than leaving a partial import. If a connection fails during the final commit acknowledgement, the operator must check the account list before retrying; the safe error output does not claim to know an uncertain commit outcome. Rerunning still skips committed existing usernames.

Dry-run mode follows the same reading, field validation, duplicate detection and existing-account checks. It reports the number that would be created, the number skipped and invalid records, without any database changes. A dry run does not reserve usernames; the real command validates and checks again.

## Password and private-file handling

Passwords are supplied by the operator in the private CSV. The trusted import service hashes each literal CSV password once using Laravel's configured hasher; the existing `User` model's `hashed` cast recognizes that newly generated hash and leaves it unchanged. Explicitly hashing the literal input also handles passwords whose text happens to resemble an encoded hash: that text is still the chosen password, not a prehashed database value. Plaintext password values must never be stored in database account fields, printed to the terminal or included in logs, exception messages or raw row dumps. Errors should identify the row and failing field without echoing its value. Do not automatically generate/reset passwords or change existing users' hashes.

Keep the working CSV at `storage/app/private/workers.csv` or another private, operator-readable path. This directory is outside Laravel's `public/` web root, its contents are Git-ignored, and it is separate from any public storage link. Do not put an import CSV in `public/`, `storage/app/public/`, attachments, screenshots, logs or Git. The command reads the supplied file without copying it into public storage. Limit file permissions, transfer it privately and remove or securely retain it under the office's password-handling policy after completing verification.

## Exact Raspberry Pi workflow

Run these commands **on the Pi**, from the existing clone. The example project directory matches [PI-INSTALLATION.md](PI-INSTALLATION.md); adjust it if your clone is elsewhere. Preserve the configured `.env`, application key, database and existing users. The importer uses normal runtime credentials and needs no migration account or extra schema permissions.

```bash
cd ~/shift-management
php artisan app:import-workers --help
umask 077
mkdir -p storage/app/private
nano storage/app/private/workers.csv
chmod 600 storage/app/private/workers.csv
```

Save the CSV in nano with **Ctrl+O**, **Enter**, then **Ctrl+X**. Enter real passwords privately in the file rather than putting them in shell commands. You may first use two fictional employees on a disposable review installation; using the real Pi's ordinary environment creates persistent accounts only when you explicitly run the actual import command.

First run validation without changing accounts:

```bash
php artisan app:import-workers storage/app/private/workers.csv --dry-run
```

Review all validation errors and the proposed new/skipped counts. Correct the private file and repeat the dry run if needed. When the counts and input are right, explicitly create the accounts:

```bash
php artisan app:import-workers storage/app/private/workers.csv
```

An absolute file path also works, for example:

```bash
php artisan app:import-workers "$PWD/storage/app/private/workers.csv" --dry-run
```

The importer reports created or would-create workers, skipped existing usernames and invalid records. It returns exit code `0` for a valid dry run or successful import, including an import where every row is skipped, and a nonzero code for invalid input or an unexpected failure. It never displays passwords. Dry-run counts are a preview, so another account creation before the actual import may increase its skipped count.

## Manual acceptance

1. On a disposable review installation, save one of the fictional two-worker files above and run `--dry-run`. Both names should be ready to create while the manager account list remains unchanged.
2. Explicitly run the import, check its successful count, and open the manager account list. Both entries should be active workers with their Hebrew names. Neither should gain manager access.
3. Sign in as each fictional worker with the password from the private file. Check the profile birthday: it should match the supplied date or be blank. Add a missing birthday through the worker's profile to confirm self-service remains available.
4. Rerun the same CSV. Both usernames should be skipped and the original accounts/passwords preserved. The same policy applies to existing managers and inactive accounts.
5. Add a short password, invalid date, duplicate normalized username or malformed row to a disposable file containing a valid new worker. Both dry run and actual import should fail without creating that valid worker. Repair the file before retrying.
6. For the real employee file, review the dry-run summary before explicitly importing, verify the manager account list, and communicate individual initial credentials using the office's private procedure. Never publish that file or paste its contents into an error report.

Implementation checks will not run this workflow against production. Owner execution on the Pi and the real employee import remain manual operations.

## Implementation reading guide

`php artisan app:import-workers <path>` → auto-discovered [ImportWorkers::handle()](../app/Console/Commands/ImportWorkers.php) → [WorkerCsvReader::read()](../app/Services/WorkerCsvReader.php) → [AccountManagementService::importWorkers()](../app/Services/AccountManagementService.php) → existing `accountAttributes()` → [User::casts()](../app/Models/User.php) → the configured MariaDB `users` table. There is no HTTP route, controller, Blade view or JavaScript enhancement for this CLI workflow.

The reader resolves a local regular file, checks UTF-8/header/CSV syntax and maps rows by header name using PHP's built-in CSV parser. It applies `(new StoreUserRequest)->rules()`, retaining the existing field/password/date requirements. `Str::trim()` mirrors the account form's Unicode-whitespace handling for text, while passwords remain literal. The HTTP uniqueness rule is handled separately because existing usernames are skipped, while duplicate normalized names inside the CSV remain errors. The reader mirrors the one supplied password into the internal confirmation field so the existing confirmed-password validation can be reused without adding a confirmation column. It uses safe field-only errors rather than printing submitted values.

`AccountManagementService::importWorkers()` uses the existing schema-specific account gate via `ManagerTransaction::run(null, ...)`, then its ordinary account attribute builder and `User` model. The null actor is a trusted terminal-only entry, like first-manager bootstrap; HTTP `create()` still requires an active authorized manager. The account gate serializes the write phase with other account administration, and the transaction protects the whole batch. The existing username unique constraint remains the final database safeguard. The service rechecks existing accounts after acquiring the gate and assigns worker/active itself. `Hash::make()` hashes the literal CSV password once, and the unchanged `User` cast accepts the resulting hash without hashing it again.

The dry-run path reads and validates the same data and checks existing usernames without invoking account creation or changing database state. The actual import starts only after successful whole-file validation. Expected validation and unexpected failures are presented through safe command output; raw database exceptions are not printed or logged by the importer.

### Files created or modified

| File | Responsibility |
|---|---|
| `app/Console/Commands/ImportWorkers.php` | New terminal entry point, option handling, safe errors/counts and exit codes. |
| `app/Services/WorkerCsvReader.php` | New private-file reader, strict CSV/header validation, normalization and existing account-rule reuse. |
| `app/Services/AccountManagementService.php` | Trusted CLI batch import under the existing account gate and transaction, reusing account attributes and hashing behavior. |
| `app/Services/ManagerTransaction.php` | Comment clarification that the existing null-actor allowance covers trusted first-manager setup and worker import; behavior is unchanged. |
| `app/Http/Requests/StoreUserRequest.php` | Comment identifying CSV validation as another consumer of the existing rules; validation behavior is unchanged. |
| `tests/Feature/WorkerImportTest.php` | Sixty command integration cases using the guarded dedicated MariaDB test database. |
| `tests/Feature/WorkerImportConcurrencyTest.php` | Four independent-process races covering concurrent imports and import versus manager account creation in both process orders. |
| `AGENTS.md` | Confirmed importer rules and implementation authorization. |
| `ROADMAP.md` | Authorized follow-up scope, review gate and verification record. |
| `README.md` | Newcomer-oriented product/roles overview, setup choices, importer usage, tests and documentation index, reorganized after importer implementation as requested. |
| `docs/DEVELOPMENT.md` | Preserves all previous detailed README workspace instructions, feature acceptance checks and request-to-code reading paths, with corrected relative links. |
| `docs/EXTRA-FEATURES-REPORT.md` | Follow-up index and delivery summary. |
| `docs/WORKER-IMPORTER.md` | Complete agreed specification, fictional CSVs, Raspberry Pi workflow and implementation report. |

The existing validation rules, `User` model, database schema, first-manager behavior, manager forms, login, frontend assets and five HTML references remain unchanged. No installation migration or new dependency is required. `StoreUserRequest` and `ManagerTransaction` receive only the explanatory comment changes listed above.

## Verification record

Verification on 2026-11-01 used the guarded dedicated MariaDB test database, not the application's development/production schema:

| Check | Actual result |
|---|---|
| `php artisan test --compact --stop-on-failure --stop-on-error` | **573 tests passed, 4132 assertions, 74.17 seconds; zero failures/skips.** Includes 64 new importer cases: 60 feature cases and four independent-process race cases. |
| `vendor/bin/pint --test` | Passed globally. |
| `composer validate --strict` | Passed. |
| `php artisan app:import-workers --help` | Passed; Artisan discovers the command and documents its path and dry-run option. |
| Local Markdown file/heading link audit | **150 links checked; zero issues.** Existing README acceptance/code-reading sections were also verified as preserved in `DEVELOPMENT.md` with relocated links. |
| `git diff --check` | Passed after the final documentation changes. |

The new cases cover successful Hebrew two-worker creation; worker role and active state; usable password hashes and hash-shaped literal passwords; existing manager/inactive-worker skips without mutation; case/Unicode-whitespace normalization; whole-file validation failure; optional/blank birthdays and later profile completion; BOM/quoted commas/multiline fields; Windows/Linux endings and empty lines; malformed headers/rows/UTF-8 with physical row numbers; reordered columns; dry-run SELECT-only behavior without hashing or writes; rollback after a later database failure; safe errors/logs; repeated imports; and both account-creation race orders.

The first focused attempt reached zero assertions because the detached local MariaDB process had stopped. The existing data directory was started in a persistent foreground process without resetting its data; subsequent focused and full runs passed. The focused run passed 61 tests / 268 assertions in 5.45 seconds before three additional malformed-header regressions were added; the full result above includes all final cases. Code review also corrected Unicode-whitespace parity and avoided a misleading guarantee about a commit result after an uncertain connection failure.

No production import or real-account creation was performed. No migration, model, route, frontend asset, environment, dependency, Git-ignore or prototype change was needed. No browser check is claimed for this terminal-only addition; the existing application browser acceptance details remain in the moved development guide and historical stage reports. Owner execution on the Raspberry Pi, the real employee import and final Stage 7 device/pilot acceptance remain pending.
