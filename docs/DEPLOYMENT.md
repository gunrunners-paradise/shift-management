# Local setup and deployment guide

Run application commands from the repository root as the deployment account. Replace example hostnames, filesystem paths and password placeholders with values chosen by the operator. Keep credentials in private environment files; never add them to source control.

Stage 7 is authorized for **local verification only** (2026-10-25). The owner will verify the Raspberry Pi later. The [Stage 7 report](STAGE7-REPORT.md) records the actual local checks, recovery rehearsal and remaining device checks; the commands in this guide are operator instructions unless that report explicitly records their execution. A successful local rehearsal does not approve the office pilot.

Stage 6 adds only an explicit manager CSV download and needs no migration, queue, scheduler, storage link or new dependency. Its fourth `קבלות` column reads the manager's selected receipt month independently of the workday range. Its request-local `php://temp` output stays in memory up to 2 MiB, then PHP uses system temporary storage removed when the stream closes. Keep the PHP temporary directory writable and private to the service account, with ordinary OS cleanup; reports are not retained as application files or backups. Database reads use a single joined snapshot with PDO buffering, so very large historical ranges still consume memory for the selected result. Measure realistic ranges before pilot approval rather than claiming unlimited export size. See [the Stage 6 report](STAGE6-REPORT.md).

Stage 5 adds worker desk reservations and manager desk administration. Apply `2026_10_22_000000_create_desk_booking_tables` with `php artisan migrate` locally, or the private migration environment described below on a server. It creates `desks` and `desk_reservations`, including nine initial desks once; existing account, shift, event and receipt rows are preserved. Normal page visits never reseed desks. No queue, scheduled task or new storage link is required. Raspberry Pi installation, HTTPS configuration and a backup/restore rehearsal have **not** been performed on the target device. The later approved desk-deletion action removes a desk and all its reservations; there is no desk-deactivation action. Keep device results pending until the operator performs them.

The authorized account-deletion follow-up adds `2026_10_22_000001_allow_deleted_account_actors`, applied by the same migration command. It makes `blocked_dates.blocked_by` and `calendar_events.created_by`/`updated_by` nullable while retaining restrictive foreign keys. It does not purge data. A manager may then permanently delete an inactive account, its owned history, sessions and photo through the confirmed form. Shared office state remains with absent actor identities. After an actor is removed, rolling back this migration refuses to invent that identity; use an appropriate reviewed backup for recovery. Existing backups are separate copies and are not rewritten by account deletion. See [the account deletion report](ACCOUNT-DELETION-REPORT.md).

The reactivation follow-up adds `2026_10_22_000002_add_session_version_to_users`, also applied with the existing migration command. It adds `users.session_version` with default zero, preserving account credentials, active flags and existing sessions. Reactivating an inactive account advances that version and clears stored sessions; a late write of an old session cannot restore access. New logins record the current version. Only the manager's explicit reactivation action changes account activity. See [the reactivation report](ACCOUNT-REACTIVATION-REPORT.md).

The prepared development workspace now uses persistent MariaDB files under `.local/mariadb/`. The workspace procedure below manages that local instance; the separate server procedure remains the deployment path for a fresh checkout or Raspberry Pi.

## Runtime requirements

- PHP 8.3 or a newer version accepted by `composer.lock`, with matching CLI and Apache PHP installations.
- Composer 2, Apache 2.4, MariaDB with InnoDB, and the MariaDB client utilities.
- PHP extensions: Ctype, cURL, DOM, Fileinfo, Filter, Hash, Mbstring, OpenSSL, PCRE, PDO, Session, Tokenizer and XML. This application also needs `pdo_mysql`; ZIP and Intl support the development workflow. GD is used by the generated-image upload test fixtures.
- Writable `storage/` and `bootstrap/cache/` directories.

These requirements follow the [Laravel 13 deployment documentation](https://laravel.com/framework/docs/13.x/deployment). The development environment has PHP 8.3.6, MariaDB 10.11.14 and Apache 2.4.58. Their presence here does not verify a Raspberry Pi installation.

On a Debian-family system, first confirm that its supported repositories provide PHP 8.3 or later. Package names vary by distribution and PHP version; an illustrative package set is:

```bash
sudo apt install apache2 mariadb-server mariadb-client php-cli libapache2-mod-php php-mysql php-mbstring php-xml php-curl php-zip php-intl php-gd php-bcmath composer unzip
php --version
php --modules
composer install
composer check-platform-reqs
```

Use a supported operating-system release and maintained packages on the Pi. Composer checks the actual locked dependencies instead of assuming that an operating-system image contains suitable PHP packages.

## Prepared workspace database

The prepared instance stores database files in `.local/mariadb/data/` and uses the Unix socket `.local/mariadb/mariadb.sock`. Its PID and diagnostic log are `.local/mariadb/mariadb.pid` and `.local/mariadb/server.log`. The directory is private to the owning Linux user. MariaDB listens only on its socket, with no TCP listener.

Private `.env` connects to `shift_management_local`; private `.env.testing` connects to the separate `shift_management_test` schema. Their random database credentials are restricted to the respective schemas. Local administrative access uses the owning Linux account's socket authentication, so managing this instance does not require the system MariaDB administrator password. Preserve these files and the existing `APP_KEY`.

Run commands from the repository root as that Linux user:

```bash
bash scripts/local-database.sh start
bash scripts/local-database.sh status
php artisan config:clear
php artisan migrate
php artisan app:create-manager
php artisan serve --host=127.0.0.1 --port=8000
```

Run `app:create-manager` in a private interactive terminal and choose the credentials yourself, as described in [first manager and sign-in](#first-manager-and-sign-in). The database script only starts, checks or gracefully stops an initialized instance; it does not create databases, run migrations or create accounts. Starting an already running instance is safe.

The database files survive stopping the process and rebooting the machine. The process is **not configured to start automatically**: after reboot, run `bash scripts/local-database.sh start` before starting the Laravel development server. Use `bash scripts/local-database.sh stop` for a graceful database shutdown when finished. This is local development tooling, not a production service setup.

Deleting this project or `.local/` loses its local database files. `.local/` is ignored by Git and is not a backup; retain separate confidential database and environment backups. Adapt the server backup examples below to this workspace's schema and socket.

If `/login` returns HTTP 500 and `storage/logs/` reports a database connection failure with a missing socket, first run:

```bash
bash scripts/local-database.sh start
bash scripts/local-database.sh status
php artisan config:clear
```

Refresh the page afterward. If startup fails, inspect `.local/mariadb/server.log`. Preserve the data directory and environment files while investigating; the script reports missing initialization rather than silently creating a replacement database.

## Persistent server MariaDB setup

Use this section for a fresh checkout or a separately managed server. The prepared workspace above already has its own database and private environment configuration.

Create databases using a MariaDB administrator, not the application account. If root uses Unix-socket authentication:

```bash
sudo env MYSQL_HISTFILE=/dev/null mariadb
```

For password-based administration, use `MYSQL_HISTFILE=/dev/null mariadb --user=ADMIN_USERNAME --password`; the client prompts for the password. Never append the password to `--password` or put it in a shell command. Enter account-creation SQL in a private administrative terminal with client history disabled. Replace each password placeholder with a different strong database password.

```sql
CREATE DATABASE shift_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'shift_app'@'localhost' IDENTIFIED BY 'REPLACE_WITH_PRIVATE_RUNTIME_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON shift_management.* TO 'shift_app'@'localhost';

CREATE USER 'shift_migrate'@'localhost' IDENTIFIED BY 'REPLACE_WITH_PRIVATE_MIGRATION_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES
    ON shift_management.* TO 'shift_migrate'@'localhost';
```

Both accounts are limited to this schema and have no global privileges or permission to grant privileges. The runtime account can operate users and database sessions. The migration account can also change the schema. If connecting over TCP rather than a local socket, have the administrator select the matching MariaDB account host and keep that access local to the intended application server.

After generating the installation key below, create a mode-`0600` private `.env.migrate` alongside `.env`. Copy the application configuration, including its `APP_KEY`, then change only the database credentials to the migration account. This file is ignored by `.gitignore`. Run schema changes explicitly with `php artisan migrate --env=migrate`; add `--force` on production. The application normally reads `.env` with runtime credentials. Do not run deployment migrations against a cached runtime configuration: run `php artisan config:clear` first, then rebuild the production cache after migration.

For a small local-only installation, one schema-scoped account with both privilege sets may be used for development. Keep the separate runtime/migration accounts for production and never use MariaDB root as `DB_USERNAME`.

## Fresh checkout configuration

For a fresh checkout:

```bash
cp .env.example .env
chmod 600 .env
```

If `.env` already exists, edit it in place instead of overwriting its key or credentials. Set `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, and either the correct `DB_HOST`/`DB_PORT` or `DB_SOCKET` for the chosen server. Passwords with spaces or reserved dotenv characters need correct dotenv quoting.

For a separately provisioned local server, use the following values with its private credentials. The prepared workspace already has its own database name and socket settings:

```dotenv
APP_ENV=local
APP_DEBUG=false
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=mariadb
DB_DATABASE=shift_management
DB_USERNAME=shift_app
DB_SOCKET=
SESSION_DRIVER=database
SESSION_LIFETIME=43200
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=false
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
CACHE_STORE=file
LOG_CHANNEL=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=7
```

The business timezone is configured as `Asia/Jerusalem` in `config/app.php`. User passwords are hashed in MariaDB; they are never configuration entries in `.env`.

Generate the application key only for a new installation:

```bash
php artisan key:generate
php artisan config:clear
```

For separate database accounts, now create the private migration environment and edit its `DB_USERNAME`/`DB_PASSWORD` before proceeding:

```bash
cp .env .env.migrate
chmod 600 .env.migrate
```

Prepare the database, choose the first manager's credentials, and start the local server:

```bash
php artisan migrate --env=migrate
php artisan app:create-manager
php artisan serve --host=127.0.0.1 --port=8000
```

If using one combined local database account, use `php artisan migrate` instead of the migration-environment command. Do not regenerate an existing installation's `APP_KEY`: it protects encrypted application data and sessions.

## First manager and sign-in

Run `php artisan app:create-manager` in an interactive terminal. The prompts are:

1. `Username`
2. `First name`
3. `Last name`
4. `Gender`: `female`, `male`, or `other`
5. `Password` — hidden terminal input
6. `Confirm password` — hidden terminal input

Choose the username and password yourself in a private terminal. Use at least 12 characters and no more than 72 UTF-8 bytes; Hebrew characters occupy multiple bytes. The command refuses an insecure visible-input fallback. Password validation matches account creation and requires confirmation. On success it prints the chosen username, `Role: manager`, and `Login URL:` derived from `APP_URL`; it never prints the password. Open that URL, sign in with the credentials you chose, and use account administration to create a worker or another manager.

Repeat setup detects an existing manager before asking questions. It neither creates a duplicate nor resets a password, including when an existing manager is inactive. Recovery of manually damaged administrator data requires a deliberate administrative procedure; rerunning bootstrap is not a password-reset workflow.

Verify the manager lands on `/admin` and can create a worker, then sign out and verify the worker lands on `/worker`. Each role must receive HTTP 403 on the other role's page. Deactivating an account must block its next authenticated request; the last active manager must remain protected.

## MariaDB test environment

Tests intentionally migrate and erase their dedicated database. Never point them at a database containing accounts you want to retain. The test harness requires `APP_ENV=testing`, a MariaDB server, and a database name ending in `_test`.

In the prepared workspace, `.env.testing` already contains the separate test schema's private connection settings. Start the workspace database, preserve this file and its key, then run `php artisan config:clear` and `php artisan test`. The remaining setup instructions are for a fresh test environment.

Create `shift_management_test` and a separate `shift_test` account using the same administrative connection. Grant the migration privileges listed above on `shift_management_test.*` only. For a new test environment, create the ignored configuration below; edit an existing `.env.testing` in place:

```bash
cp .env.testing.example .env.testing
chmod 600 .env.testing
```

Fill in its private credentials and connection settings, preserving `APP_ENV=testing`, `DB_DATABASE=shift_management_test`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, and `BCRYPT_ROUNDS=4`. Generate a key only when creating a new test environment without one:

```bash
php artisan config:clear
php artisan key:generate --env=testing
```

Keep an existing test environment's key. To run the configured suite:

```bash
php artisan config:clear
php artisan test
vendor/bin/pint --test
```

`composer test` also clears cached configuration before running the suite. The concurrency scenarios require PHP `pcntl` and local socket pairs; read the test output and report any skipped scenarios. Database tests use MariaDB rather than substituting SQLite.

## Raspberry Pi and Apache configuration

Use a portable application directory such as `/srv/shift-management`, owned by the deployment account. Replace that path and `attendance.example.internal` throughout the example. Apache must serve **only `public/`**; repository-root deployment exposes secrets and the read-only HTML references.

Install production dependencies from the reviewed lock file with `composer install --no-dev --optimize-autoloader`, and run `composer check-platform-reqs --no-dev`. Run `composer audit --locked --no-dev` when advisory access is available; investigate findings before deployment and record a network failure as an incomplete advisory check. Do not run `composer update` during deployment. Configure PHP through the distribution's supported Apache module or PHP-FPM setup. Match its version/extensions to the checked CLI runtime.

Copy [the Apache virtual-host example](deployment/apache-vhost.conf) to the distribution's sites directory after replacing its paths and hostname. It uses an organization-approved certificate trusted by office browsers. Configure the distribution's PHP handler separately; an FPM installation needs its supported FPM handler configuration. The key directives are:

```apache
<VirtualHost *:80>
    ServerName attendance.example.internal
    Redirect permanent / https://attendance.example.internal/
</VirtualHost>

<VirtualHost *:443>
    ServerName attendance.example.internal
    DocumentRoot /srv/shift-management/public

    SSLEngine on
    SSLCertificateFile /PATH/TO/CERTIFICATE_CHAIN.pem
    SSLCertificateKeyFile /PATH/TO/PRIVATE_KEY.pem

    <Directory /srv/shift-management>
        AllowOverride None
        Require all denied
    </Directory>

    <Directory /srv/shift-management/public>
        Options -Indexes -ExecCGI +FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex index.php

        <FilesMatch "(?i)\.(php[0-9]*|phtml|phar|phps)$">
            Require expr "%{REQUEST_FILENAME} == '/srv/shift-management/public/index.php'"
        </FilesMatch>
        <FilesMatch "^\.">
            Require all denied
        </FilesMatch>
    </Directory>

    <Directory /srv/shift-management/storage>
        AllowOverride None
        Require all denied
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/shift-management-error.log
    CustomLog ${APACHE_LOG_DIR}/shift-management-access.log combined
</VirtualHost>
```

The supplied `public/.htaccess` routes requests to Laravel. The parent directory denial protects code outside `public/`; the explicit storage denial keeps private uploads unavailable through an accidental filesystem alias. Dotfiles are denied within `public/`. The PHP rule compares the full mapped filename with the exact front-controller path, so a nested `index.php` is denied too. Replace that expression's path whenever changing `DocumentRoot`. Apache documents [directory access](https://httpd.apache.org/docs/2.4/mod/core.html#directory), [expression authorization](https://httpd.apache.org/docs/2.4/mod/mod_authz_core.html#reqexpr) and the [mapped filename variable](https://httpd.apache.org/docs/2.4/expr.html#vars). Enable the chosen configuration, then validate it before reloading:

```bash
sudo a2enmod rewrite ssl
sudo a2ensite shift-management.conf
sudo apache2ctl configtest
sudo apache2ctl -S
sudo systemctl reload apache2
```

Restrict the host firewall to the office network and approved administration path; do not forward this office pilot through the public router. Keep MariaDB on the local socket or an explicitly private interface. Verify that DNS resolves the chosen hostname and that the certificate chain and hostname are trusted on an actual office browser. Check expiry/renewal ownership before relying on it. Do not use a browser certificate exception as the HTTPS verification result. If a reverse proxy is introduced later, configure only its known trusted addresses and HTTPS forwarding after reviewing that topology; no wildcard proxy trust is required for the direct Apache setup.

For an explicitly local HTTP development virtual host, use the `DocumentRoot`/`Directory` directives on port 80 without the redirect, and retain `SESSION_SECURE_COOKIE=false`. The production HTTPS environment must use:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://attendance.example.internal
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
LOG_CHANNEL=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=7
```

Give the web process read access to application code and `.env`, and write access only to Laravel's writable directories. Run these examples from the application directory, replacing `www-data` if the web group differs:

```bash
sudo chgrp www-data .env
chmod 640 .env
sudo chgrp -R www-data storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod 2770 {} +
find storage bootstrap/cache -type f -exec chmod 660 {} +
chmod 600 .env.migrate
```

The deployment account must own those files or have the necessary group access. Avoid world-writable permissions. Run Composer/Artisan as that account so later deployments retain consistent ownership.

Keep code and `public/` unwritable by the web process. Review parent-directory traversal permissions as well as individual files. The web group may read the runtime `.env` but must not read `.env.migrate`, the certificate private key, administrative DB option files or backup archives. Laravel's `bootstrap/cache/config.php` contains resolved secrets when configuration is cached: keep `bootstrap/cache/` outside the document root and restricted to the deployment/web accounts, and do not publish or copy that cache between environments.

Profile photos are private files under `storage/app/private/avatars/`, with random server-generated filenames and private visibility. Keep this directory outside Apache's `public/` document root and do not expose it through a symlink or alias. The authenticated avatar controller serves approved image MIME types; uploaded SVGs and arbitrary paths are rejected. No `storage:link` is required for Stage 3. Back up this directory with the database, preserve private permissions after restore, and verify an authenticated photo request as part of recovery. Replaced photos are removed after commit; filesystem cleanup failures produce a path-free warning in Laravel's log.

The application accepts JPG, PNG and WebP files up to 2048KB, with width/height at most 2048 pixels. Configure the web PHP installation with `upload_max_filesize` at least `2M` and `post_max_size` at least `8M`, then reload the applicable PHP/Apache service. Keep the form's documented application limit even if the server allows larger uploads. Monitor private upload storage as part of the Pi's disk checks.

With the site in maintenance mode for an update, back up first, clear cached configuration, migrate using the private migration environment, and rebuild caches with runtime configuration:

```bash
php artisan down --retry=60
php artisan config:clear
php artisan migrate --env=migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan up
```

Check Apache and application logs after deployment. `/up` confirms that Laravel booted; it does not prove database connectivity or a successful login. Once the site is up, verify:

- HTTP redirects to the configured HTTPS hostname; HTTPS `/login` succeeds without a certificate exception and sets session cookies with `Secure`, `HttpOnly` and `SameSite=Lax`. The idle lifetime remains 30 days. Logout/deactivation still revoke access immediately.
- `/.env`, `/.env.migrate`, `/composer.lock`, `/AGENTS.md`, the five reference `.html` filenames and `/storage/app/private/` return 403 or 404 without file content. Temporary harmless non-front-controller `.php` and nested `index.php` probes under `public/` must be denied; remove them after the check. Do not use executable or secret-bearing probes.
- An unauthenticated avatar request requires login. An authenticated upload of a supported image remains readable after a reload, while its raw storage path stays inaccessible. The front controller, CSS, JavaScript, logo and default avatar load successfully.
- A manager and worker each reach their own destination, role-exclusive endpoints reject the other role, and the workflow described in the [Stage 7 report](STAGE7-REPORT.md) works with `APP_DEBUG=false`. Check a fresh browser session, native forms and mobile RTL layout before handing over the pilot.

When updating, do not use `migrate:rollback` as an automatic recovery step: deletion and edited business records may make a reverse migration unsafe. Keep the old reviewed source revision and pre-update database/files backup available. If an update fails, keep maintenance mode while diagnosing; an operator chooses a compatible code rollback or a reviewed full restore. Record any data written since the backup before choosing to discard it.

## Off-device backup and restore rehearsal

Back up the database, the private `.env` containing the original `APP_KEY`, `storage/app/`, and the exact source revision/`composer.lock`. Protect backups as confidential account data and store them on encrypted off-device media or a secured backup server. Copying backups elsewhere on the Pi's own SD card does not protect against device failure. Agree who owns backup execution, its frequency and retention before the pilot; no scheduled backup or report job is installed by this application.

For the current application tables, a dedicated database backup account needs `SELECT` and `SHOW VIEW` on the application schema. Create it in the private administrative SQL session:

```sql
CREATE USER 'shift_backup'@'localhost' IDENTIFIED BY 'REPLACE_WITH_PRIVATE_BACKUP_PASSWORD';
GRANT SELECT, SHOW VIEW ON shift_management.* TO 'shift_backup'@'localhost';
```

The following manual procedure writes directly to an already mounted off-device directory. Replace the mount path and dated backup directory before use. Check every command's exit status and stop on failure; do not declare a failed or partial dump usable. Maintenance mode blocks new application requests while the database and files are copied together. Wait for already running writes to finish, and pause other administrative writers and schema deployments during this short backup window.

```bash
umask 077
mountpoint /mnt/office-backups
mkdir /mnt/office-backups/shift-management-YYYYMMDD-HHMMSS
php artisan down --retry=60
mariadb-dump --host=localhost --user=shift_backup --password --single-transaction --quick --skip-lock-tables --skip-triggers --result-file=/mnt/office-backups/shift-management-YYYYMMDD-HHMMSS/database.sql shift_management
tar --create --gzip --file=/mnt/office-backups/shift-management-YYYYMMDD-HHMMSS/private-files.tar.gz -- .env storage/app
cp composer.lock /mnt/office-backups/shift-management-YYYYMMDD-HHMMSS/composer.lock
git rev-parse HEAD > /mnt/office-backups/shift-management-YYYYMMDD-HHMMSS/source-revision.txt
```

Record any reviewed, uncommitted deployment changes separately; a commit ID alone cannot recover them. From the completed backup directory, generate checksums with `sha256sum database.sql private-files.tar.gz composer.lock source-revision.txt > SHA256SUMS`. Store the matching source bundle or ensure the recorded revision is recoverable. If a command fails, preserve the partial backup for diagnosis, mark it incomplete, and explicitly decide whether to retry or bring the unchanged site back with `php artisan up`; do not leave maintenance mode unnoticed.

While writers are paused, also save a private recovery manifest with exact table counts and a checksum for each avatar. Record `COUNT(*)` for `users`, `shifts`, `desk_reservations`, `monthly_receipts`, `calendar_events`, `blocked_dates`, `desks` and `calendar_dates`, plus canceled-shift and canceled-reservation counts (`WHERE cancelled_at IS NOT NULL`). Use a read-only administrative/backup connection; no personal fields need to be listed. The manifest belongs beside the confidential backup, not in Git. This makes restoration verifiable even if the live office database changes afterward.

Once the dump, files and manifest are complete, resume the original site:

```bash
php artisan up
```

`--single-transaction` provides an InnoDB snapshot; avoid schema changes during the dump. The current schema defines no triggers, procedures or database events, so `--skip-triggers` supports the restricted backup account. Keep all tables, stable desk identities, cancellation history and permanent date-lock records. Preserve generated `shifts.active_slot` and `desk_reservations.active_slot` columns, uniqueness and CHECK constraints when restoring. Desk overlap safety also depends on the application transactions, not only schema constraints. Revisit that choice if approved schema changes introduce triggers. Use compatible MariaDB dump/import clients. These details follow the [MariaDB dump documentation](https://mariadb.com/docs/server/clients-and-utilities/backup-restore-and-import-clients/mariadb-dump).

Rehearse restoration into a **separate application directory and a new disposable schema**, never over the live database:

1. Verify the archive with `sha256sum --check SHA256SUMS` from its directory. Restore the matching source revision and run Composer using its lock file.
2. Have the database administrator create `shift_management_restore` and a `shift_restore` account with schema-scoped migration privileges. Do not grant that account access to the live schema.
3. Import with `mariadb --host=localhost --user=shift_restore --password shift_management_restore < database.sql`. The dump names no destination database, so the command selects the disposable schema.
4. Extract `private-files.tar.gz` **inside the new application directory**. Edit the restored `.env` before running Artisan: select the restore schema/account, a distinct test URL, and `SESSION_COOKIE=shift_management_restore_session`. Preserve the backed-up `APP_KEY`; do not run `key:generate` on a restored installation.
5. Clear restored database sessions with `mariadb --host=localhost --user=shift_restore --password shift_management_restore --execute='DELETE FROM sessions'`. This prevents copied sessions from authenticating users in the rehearsal.
6. Restore writable-directory permissions and run `php artisan config:clear` before any other Artisan command so an old cached configuration cannot select the live database. Keep `APP_DEBUG=false`. Serve the rehearsal at its separate URL, using `SESSION_SECURE_COOKIE=false` only if that URL is local HTTP.
7. Compare database table counts against the backup manifest, including active/canceled shifts, desk reservations, receipts, events/blocks and `calendar_dates`. Check `SHOW CREATE TABLE shifts`, `SHOW CREATE TABLE desk_reservations` and `SHOW CREATE TABLE monthly_receipts`: generated active-slot columns, unique indexes, foreign keys and CHECK constraints must still exist. Verify all application tables use InnoDB. Clear only the restored sessions as described above; do not remove date-lock keys or cancellation history.
8. Compare a checksum of every restored avatar against the source backup, then request one through the authenticated avatar route. Verify manager login with the known existing credentials, creation of a disposable worker, role isolation and account deactivation. Exercise a shift, desk booking, date closure/reopening and a CSV download to show that restored data and permissions support the application. Use only synthetic accounts when rehearsing locally; do not reset a real user's password to make a rehearsal convenient.
9. Record the source revision, runtime/client versions, start/end times, dump/import exit statuses, row/constraint/file comparisons, sign-in/workflow results, backup location and any recovery issues in a private operator record. Never put the original key, passwords, private backup content or user data into a public report.

Keep the live site available during the isolated rehearsal. A real recovery requires an explicit operator decision to switch the reviewed application/database into service. The same environment-based configuration and database/files backup support migration to the company's central server: provision a new schema and accounts, restore the matching source/files/key, change only environment-specific connection/URL/cookie settings, rebuild caches there, and verify it at a separate URL before switching office DNS. Arrange one active writer during cutover so changes are not split between servers. Retire access to the old instance only after the operator accepts the new one.

The Stage 7 local rehearsal uses disposable synthetic data and is recorded in [its report](STAGE7-REPORT.md). A local `/tmp` archive demonstrates recovery mechanics; it is not the office's off-device backup. Pi restore timing, actual backup-media access and the office cutover remain pending.

## Storage, logs and Raspberry Pi resource checks

The 8 GB RAM / 32 GB storage target is a deployment constraint, not a measured result on this development host. Record the following on the actual Pi before approving the pilot:

```bash
free -h
df -h /
df -i /
du -sh storage/app/private/avatars storage/logs bootstrap/cache vendor
sudo journalctl --disk-usage
```

Use the administrator's private DB connection to measure table/index usage without exporting worker data:

```sql
SELECT TABLE_NAME, ENGINE, TABLE_ROWS,
       ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS size_mib
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'shift_management'
ORDER BY TABLE_NAME;
```

`TABLE_ROWS` is an estimate for InnoDB; use explicit counts when comparing a backup and restore. Include OS packages, MariaDB's data/log files, PHP temporary files, updates and filesystem overhead in the free-space measurement. Keep backup archives off the SD card. Each retained avatar is limited to 2 MiB, so 1,000 current photos alone can approach 2 GiB before filesystem overhead. Replaced photos are cleaned up after commit, but failed cleanup is logged; investigate it rather than deleting arbitrary application files.

Laravel's `daily` channel retains seven daily files with the supplied environment settings. That limits retained days, not bytes written within one day. Use the distribution's existing Apache and MariaDB rotation policies; check that the `shift-management-*.log` files match the Apache policy and avoid adding a second policy for the same files. On a distribution using logrotate, `sudo logrotate --debug /etc/logrotate.conf` previews its rules without rotating files. Check the actual configured journal retention as well; `journalctl --disk-usage` only measures it. Choose caps appropriate to the measured free space and organization retention needs.

During the pilot, review free space and unexpected log growth daily and after updates; investigate before space falls below the operator's agreed threshold. If disk pressure occurs, preserve diagnostic evidence, move completed backup copies off-device and rotate logs according to the reviewed policy. Do not delete the live MariaDB directory, account history, date-lock rows or application photos as an improvised cleanup. No automatic history purge is installed.

Measure page response time and PHP/MariaDB memory while two workers contend for one desk and while a manager downloads a representative long historical report. Record worker count, date range, returned CSV size, elapsed time and peak PHP memory without retaining account data in the public report. PDO buffers CSV query results, so the 2 MiB output-stream threshold is not a bound on total PHP memory. Set Apache/FPM worker limits from measured per-worker memory and leave room for MariaDB, the OS and maintenance tasks. A local host result cannot establish the Pi's safe concurrency limit; repeat the measurements on the device.

## Device handover evidence still required

The owner deferred these checks until the Raspberry Pi is available:

- Supported Pi OS/PHP/Composer installation, production extensions and InnoDB with schema-scoped runtime/migration/backup users.
- Real Apache virtual-host selection and permissions, office-trusted HTTPS chain and hostname, HTTP redirect, production session cookies, private upload access and denial of repository files.
- End-to-end manager/worker checks on the office network, including mobile RTL and a simultaneous desk-booking attempt from two browsers.
- A successful off-device backup plus isolated restore on the intended equipment, with original key/files, constraints, counts, avatar checksums and sign-in verified.
- Pi disk/RAM measurements under representative use, effective log rotation, backup ownership/retention, reboot/service recovery and power-loss recovery arrangements.

Keep these items marked **pending** until the operator records evidence and explicitly approves office pilot readiness.
