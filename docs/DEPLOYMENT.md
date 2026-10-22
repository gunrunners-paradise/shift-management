# Local setup and deployment guide

Run application commands from the repository root as the deployment account. Replace example hostnames, filesystem paths and password placeholders with values chosen by the operator. Keep credentials in private environment files; never add them to source control.

Stage 5 adds worker desk reservations and manager desk administration. Apply `2026_10_22_000000_create_desk_booking_tables` with `php artisan migrate` locally, or the private migration environment described below on a server. It creates `desks` and `desk_reservations`, including nine initial desks once; existing account, shift, event and receipt rows are preserved. Normal page visits never reseed desks. No queue, scheduled task or new storage link is required. Raspberry Pi installation, HTTPS configuration and a backup/restore rehearsal have **not** been performed on the target device. Record those results during the Stage 7 deployment review before approving an office pilot.

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

Install production dependencies with `composer install --no-dev --optimize-autoloader`, and run `composer check-platform-reqs --no-dev`. Configure PHP through the distribution's supported Apache module or PHP-FPM setup. Match its version/extensions to the checked CLI runtime.

Create a virtual-host configuration using an organization-approved certificate trusted by office browsers. This example uses Apache's PHP module; an FPM installation additionally needs its distribution's PHP handler configuration:

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

    <Directory /srv/shift-management/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/shift-management-error.log
    CustomLog ${APACHE_LOG_DIR}/shift-management-access.log combined
</VirtualHost>
```

The supplied `public/.htaccess` routes requests to Laravel. Apache's [virtual-host examples](https://httpd.apache.org/docs/2.4/vhosts/examples.html) explain how hostnames select a site. Enable the chosen configuration, then validate it before reloading:

```bash
sudo a2enmod rewrite ssl
sudo a2ensite shift-management.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

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

Check Apache and application logs after deployment. The application keeps seven daily log files with the documented settings; also configure the distribution's Apache/MariaDB log rotation. Monitor free space on the Pi's 32 GB storage and keep backup archives off the device. `/up` confirms that Laravel booted; it does not prove database connectivity or a successful login.

## Off-device backup and restore rehearsal

Back up the database, the private `.env` containing the original `APP_KEY`, `storage/app/`, and the exact source revision/`composer.lock`. Protect backups as confidential account data and store them on encrypted off-device media or a secured backup server. Copying backups elsewhere on the Pi's own SD card does not protect against device failure.

For the current application tables, a dedicated database backup account needs `SELECT` and `SHOW VIEW` on the application schema. Create it in the private administrative SQL session:

```sql
CREATE USER 'shift_backup'@'localhost' IDENTIFIED BY 'REPLACE_WITH_PRIVATE_BACKUP_PASSWORD';
GRANT SELECT, SHOW VIEW ON shift_management.* TO 'shift_backup'@'localhost';
```

The following manual procedure writes directly to an already mounted off-device directory. Replace the mount path and dated backup directory before use. Check every command's exit status and stop on failure; do not declare a failed or partial dump usable.

```bash
umask 077
mountpoint /mnt/office-backups
mkdir /mnt/office-backups/shift-management-YYYYMMDD-HHMMSS
php artisan down --retry=60
mariadb-dump --host=localhost --user=shift_backup --password --single-transaction --quick --skip-lock-tables --skip-triggers --result-file=/mnt/office-backups/shift-management-YYYYMMDD-HHMMSS/database.sql shift_management
tar --create --gzip --file=/mnt/office-backups/shift-management-YYYYMMDD-HHMMSS/private-files.tar.gz -- .env storage/app
cp composer.lock /mnt/office-backups/shift-management-YYYYMMDD-HHMMSS/composer.lock
php artisan up
```

Record the deployed source revision next to the archive. From the completed backup directory, generate checksums with `sha256sum database.sql private-files.tar.gz composer.lock > SHA256SUMS`. Store the matching source bundle or ensure the recorded revision is recoverable.

`--single-transaction` provides an InnoDB snapshot; avoid schema changes during the dump. The current schema defines no triggers, procedures or database events, so `--skip-triggers` supports the restricted backup account. Keep all tables, stable desk identities, cancellation history and permanent date-lock records. Preserve generated `shifts.active_slot` and `desk_reservations.active_slot` columns, uniqueness and CHECK constraints when restoring. Desk overlap safety also depends on the application transactions, not only schema constraints. Revisit that choice if approved schema changes introduce triggers. Use compatible MariaDB dump/import clients. These details follow the [MariaDB dump documentation](https://mariadb.com/docs/server/clients-and-utilities/backup-restore-and-import-clients/mariadb-dump).

Rehearse restoration into a **separate application directory and a new disposable schema**, never over the live database:

1. Verify the archive with `sha256sum --check SHA256SUMS` from its directory. Restore the matching source revision and run Composer using its lock file.
2. Have the database administrator create `shift_management_restore` and a `shift_restore` account with schema-scoped migration privileges. Do not grant that account access to the live schema.
3. Import with `mariadb --host=localhost --user=shift_restore --password shift_management_restore < database.sql`. The dump names no destination database, so the command selects the disposable schema.
4. Extract `private-files.tar.gz` **inside the new application directory**. Edit the restored `.env` before running Artisan: select the restore schema/account, a distinct test URL, and `SESSION_COOKIE=shift_management_restore_session`. Preserve the backed-up `APP_KEY`; do not run `key:generate` on a restored installation.
5. Clear restored database sessions with `mariadb --host=localhost --user=shift_restore --password shift_management_restore --execute='DELETE FROM sessions'`. This prevents copied sessions from authenticating users in the rehearsal.
6. Restore writable-directory permissions, run `php artisan config:clear`, and serve the rehearsal at its separate URL. Use `SESSION_SECURE_COOKIE=false` only if that URL is local HTTP. Verify user counts, manager login, creation of a disposable worker, role isolation and deactivation. Record the results, duration and any recovery issues.

Keep the live site available during the isolated rehearsal. A real recovery requires an explicit operator decision to switch the reviewed application/database into service. The same environment-based configuration and database/files backup support migration to the company's central server. Stage 7 must validate this procedure on the intended deployment hardware and record an actual successful restore.
