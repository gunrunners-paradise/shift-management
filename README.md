# מערכת נוכחות

A Hebrew, right-to-left application for planning office shifts, reserving desks and managing worker accounts. Built using the assistance of Codex with Laravel, Blade, ordinary CSS, vanilla JavaScript and MariaDB for a Raspberry Pi or another Linux server.

## Contents

- [Start here](#start-here)
- [Features and roles](#features-and-roles)
- [Requirements](#requirements)
- [Installation](#installation)
- [Import worker accounts](#import-worker-accounts)
- [Reset a forgotten password](#reset-a-forgotten-password)
- [Testing](#testing)
- [Documentation](#documentation)
- [Project status](#project-status)

## Start here

| What you want to do | Guide |
|---|---|
| Run the site on a Raspberry Pi over office Wi-Fi, on port **8000** | [Raspberry Pi installation](docs/PI-INSTALLATION.md) |
| Deploy with Apache and HTTPS, or configure a fresh MariaDB installation | [Deployment and recovery](docs/DEPLOYMENT.md) |
| Run the already prepared local development workspace | [Workspace instructions](docs/DEVELOPMENT.md#run-in-this-workspace) |
| Import employee accounts from a private CSV | [Worker importer](docs/WORKER-IMPORTER.md) |
| Change a worker's or another manager's forgotten password | [Manager password reset](docs/MANAGER-PASSWORD-RESET.md) |
| Review features or follow the source code | [Development and manual review](docs/DEVELOPMENT.md) |

## Features and roles

| Role | Available actions |
|---|---|
| Worker | Choose shifts, reserve a desk, maintain a personal profile and mark the current month's receipts. |
| Manager | Create/manage accounts, reset another account's password, manage events, holidays, blocked dates and desks; view monthly receipts and download workday CSV reports. Managers do not book shifts/desks or submit worker receipts. |

- Sunday–Thursday weekly calendar with five shift choices: **09:00–13:00**, **10:00–14:00**, **11:00–15:00**, **12:00–16:00**, or **09:00–16:30**. Workers select one period per date, from today through the next 28 calendar days.
- Desk reservations independent of shifts, with overlap protection and server-side time/date validation. Blocking a workday cancels and prevents both shift selections and desk reservations.
- Event/holiday titles beneath each matching calendar date, including future viewed weeks. Below the calendar: receipts first, then today's announcements, birthdays and coworkers.
- Monthly receipt tracking and manager-requested CSV downloads with scheduled workday counts, dates and receipt status for an explicitly chosen month.
- Personal profiles, private avatars, password changes, account suspension/reactivation and explicit account/desk deletion.
- Username/password login with **30 days of inactivity** per session. Application requests renew that period; logout, account deactivation and manager password resets revoke the affected access.

Dates follow `Asia/Jerusalem`. CSV workday reports describe scheduled shifts, rather than verified attendance or paid hours. There is no public registration or default password.

## Requirements

- **PHP 8.3+**, Composer 2 and the extensions listed in [runtime requirements](docs/DEPLOYMENT.md#runtime-requirements).
- **MariaDB with InnoDB**; the application and integration tests use MariaDB.
- Writable Laravel `storage/` and `bootstrap/cache/` directories.
- Apache/HTTPS for the documented office deployment, or the separate development-server setup for LAN testing.

CSS and JavaScript are served directly from `public/`; no Node build, frontend framework, Redis or queue worker is required. `composer.lock` records PHP dependencies.

## Installation

### Raspberry Pi

Follow [PI-INSTALLATION.md](docs/PI-INSTALLATION.md) for the requested LAN testing setup: system MariaDB and a systemd-managed Artisan server listening on port **8000**. The guide includes fresh installation, existing-installation startup, Wi-Fi access and troubleshooting. Run its commands on the Pi.

For regular office hosting, follow [the Apache and HTTPS deployment guide](docs/DEPLOYMENT.md#raspberry-pi-and-apache-configuration) and its [vhost example](docs/deployment/apache-vhost.conf). Apache's document root must be Laravel's **`public/`** directory. The port-8000 guide uses PHP's development server and is documented as a testing setup.

### Local development

For a fresh clone, install dependencies with `composer install`, then follow [fresh checkout configuration](docs/DEPLOYMENT.md#fresh-checkout-configuration) to provision MariaDB and your private environment before migrating and creating the first manager. The [first-manager command](docs/DEPLOYMENT.md#first-manager-and-sign-in) is `php artisan app:create-manager`; it asks for a chosen username and hidden, confirmed password, then prints the login URL. Passwords require at least 12 characters and at most 72 UTF-8 bytes.

The prepared development workspace already has private `.env`/`.env.testing` files and a persistent database under `.local/mariadb/`. Use its [workspace commands](docs/DEVELOPMENT.md#run-in-this-workspace). `scripts/local-database.sh` manages that prepared database; a fresh Git clone does not include it, and the Pi uses system MariaDB instead.

### Preserve existing data

A Git clone contains the application source and installation files. Deployment also needs installed Composer dependencies, private environment configuration and a running database. To move existing users/history, restore MariaDB, private `storage/app/` uploads and the original `APP_KEY`; Git does not contain those records or secrets. Preserve `.env` and any private `.env.migrate` used by your installation. Ordinary updates and restarts do not require resetting tables, recreating accounts or regenerating the key.

Keep protected off-device backups using [the backup and restore procedure](docs/DEPLOYMENT.md#off-device-backup-and-restore-rehearsal). Ignored files can still be essential runtime data: `.env`, uploaded avatars and database backups must remain private, while `vendor/` can be recreated with Composer.

## Import worker accounts

Save a UTF-8 CSV privately as `storage/app/private/workers.csv`. The recommended header is:

```csv
username,first_name,last_name,gender,birth_date,password
```

`birth_date` may be blank or its entire column omitted. Gender values are `female`, `male` or `other`; passwords follow the same rules as account creation. The importer automatically creates **active workers**, so the file has no `role` or `is_active` column.

From the Laravel project root, validate before explicitly importing:

```bash
php artisan app:import-workers storage/app/private/workers.csv --dry-run
php artisan app:import-workers storage/app/private/workers.csv
```

Existing usernames are skipped without changing their accounts. Invalid input or duplicate normalized usernames within the CSV abort the whole batch; new accounts are created in a single transaction. A dry run writes nothing. Verify new accounts in the manager account list and test sign-in. CSV files contain plaintext passwords: keep them outside Git and public directories.

[The importer guide](docs/WORKER-IMPORTER.md) includes the full format, fictional two-worker examples, exact Pi commands, verification results and the source-code reading path.

## Reset a forgotten password

As a manager, open **רשימת משתמשים** in the dashboard, find the account and expand **שינוי סיסמה**. Enter the new password twice and choose **שמירת סיסמה**. Passwords require at least 12 characters and at most 72 UTF-8 bytes. Give the new password to that person privately; the application does not send or display it afterward.

This works for other workers and managers, including inactive accounts. Their existing sessions end and active users sign in with the new password; inactive accounts remain disabled until separately reactivated. Profiles, bookings and history stay intact. There is no required password change at the next login. To change your own password, use **הפרופיל שלי** and enter your current password.

[The password-reset guide](docs/MANAGER-PASSWORD-RESET.md) covers the policy, manual review and implementation checks. Updating an existing installation for this feature needs no database migration or new dependency.

## Testing

Configure a **separate MariaDB database ending in `_test`** using [the test setup guide](docs/DEPLOYMENT.md#mariadb-test-environment). Tests erase that schema; never point them at development or production data. The test harness checks the testing environment, database suffix and MariaDB server before migrations.

With that dedicated test configuration ready:

```bash
php artisan config:clear
php artisan test
vendor/bin/pint --test
composer validate --strict
composer check-platform-reqs
```

Race tests use independent MariaDB connections and require PHP `pcntl` and local socket pairs; missing support is reported as skipped. Run only one suite at a time per test schema. [Development instructions](docs/DEVELOPMENT.md#automated-verification) preserve the workspace-specific commands and detailed manual acceptance checks.

## Documentation

| Document | Contents |
|---|---|
| [Pi installation](docs/PI-INSTALLATION.md) | Direct Wi-Fi access on port 8000, MariaDB, service startup and troubleshooting. |
| [Deployment](docs/DEPLOYMENT.md) | Runtime prerequisites, fresh setup, Apache/HTTPS, database permissions, backups and restore. |
| [Development and manual review](docs/DEVELOPMENT.md) | Prepared-workspace commands, existing feature acceptance checks and route/controller/service/model/view reading guide. |
| [Worker importer](docs/WORKER-IMPORTER.md) | Complete importer specification, CSV examples, Pi commands, implementation and test results. |
| [Manager password reset](docs/MANAGER-PASSWORD-RESET.md) | Account-card instructions, session revocation, agreed policy, implementation and verification. |
| [Roadmap](ROADMAP.md) | Stage scopes, authorizations, delivery history and review gates. |
| [Agent instructions](AGENTS.md) | Confirmed business rules, design priorities and code standards. |
| [Extra features](docs/EXTRA-FEATURES-REPORT.md) | Additions and corrections outside numbered stage deliveries, with detailed report links. |
| [Stage 7 report](docs/STAGE7-REPORT.md) | Local audit, browser checks, synthetic deployment/restore rehearsal and outstanding device checks. |

Earlier delivery reports are [Stage 1](docs/STAGE1-REPORT.md), [Stage 2](docs/STAGE2-REPORT.md), [Stage 3](docs/STAGE3-REPORT.md), [Stage 4](docs/STAGE4-REPORT.md), [Stage 5](docs/STAGE5-REPORT.md) and [Stage 6](docs/STAGE6-REPORT.md). Their results describe the dates and implementations recorded there.

## Project status

Stages 1–6 have been delivered and Stage 7's local audit/rehearsal is complete. The importer and manager password reset are authorized follow-ups. Raspberry Pi verification and final office-pilot approval remain pending; local test success does not replace those checks.

The five HTML files in the repository root are read-only design references. Production serves the Laravel application through `public/`.
