# Raspberry Pi installation: Wi-Fi access on port 8000

This guide uses system MariaDB and runs Laravel as a systemd service. The site stays in the cloned project folder and opens at **http://192.168.1.10:8000/login**. Apache and a port-80 proxy are unnecessary for this setup.

The examples use Linux user `pi-enterb7`, project folder `/home/pi-enterb7/shift-management`, and Pi IP `192.168.1.10`. Replace those example values if your Pi differs. Run all commands **on the Pi**, using its terminal or SSH; editing files on your other computer does not update the Pi.

This is the requested LAN testing setup. Artisan uses [PHP's development server](https://www.php.net/manual/en/features.commandline.webserver.php), which is not intended for production hosting. The [full deployment guide](DEPLOYMENT.md#raspberry-pi-and-apache-configuration) covers Apache and HTTPS for regular office deployment.

**Already installed?** If the database, environment files and manager account are ready, go to [step 6](#6-run-the-site-as-a-service). Keep the existing passwords, application key and records.

## 1. Prepare the Pi and project

Use a supported Raspberry Pi OS/Debian release with PHP **8.3 or newer** and Composer 2. Package versions depend on the OS release. Install the required tools:

```bash
sudo apt update
sudo apt install git openssl mariadb-server mariadb-client php-cli php-mysql php-mbstring php-xml php-curl php-zip php-intl php-gd php-bcmath composer unzip
php --version
```

If PHP is older than 8.3, resolve the OS/package version before continuing. Clone this GitHub project into `~/shift-management`, or use your existing clone. Then:

```bash
cd ~/shift-management
composer install --no-dev --optimize-autoloader
composer check-platform-reqs --no-dev
sudo systemctl enable --now mariadb
hostname -I
```

Note the Pi's Wi-Fi IPv4 address for `APP_URL` and your browser. Reserve that address in the router if it should stay stable. Run Composer and Artisan as your normal Linux user. No Node build is needed.

**Use system MariaDB on the Pi.** `scripts/local-database.sh` controls an already prepared development database under `.local/`; a Git clone does not contain that database.

## 2. Create the database and choose two passwords

This step is for a **fresh, empty installation**. An existing database does not need recreating.

Generate two different passwords in your private Pi terminal:

```bash
openssl rand -hex 24
openssl rand -hex 24
```

Save the first result as the **runtime password** and the second as the **migration password**. These are database passwords, separate from your Linux password and the manager's site-login password.

Open MariaDB with SQL history disabled:

```bash
sudo env MYSQL_HISTFILE=/dev/null mariadb
```

**Before executing the following SQL, replace `<RUNTIME_PASSWORD>` and `<MIGRATION_PASSWORD>` with your two generated values. Keep the surrounding single quotes. Pasting the placeholder unchanged makes that literal text the account's password.**

```sql
CREATE DATABASE shift_management
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'shift_app'@'localhost' IDENTIFIED BY '<RUNTIME_PASSWORD>';
GRANT SELECT, INSERT, UPDATE, DELETE
    ON shift_management.* TO 'shift_app'@'localhost';

CREATE USER 'shift_migrate'@'localhost' IDENTIFIED BY '<MIGRATION_PASSWORD>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES
    ON shift_management.* TO 'shift_migrate'@'localhost';

SELECT @@socket;
EXIT;
```

Note the socket path printed by `SELECT @@socket`. On the Pi used in our setup it was `/run/mysqld/mysqld.sock`. To retrieve it again:

```bash
sudo mariadb --batch --skip-column-names --execute="SELECT @@socket;"
```

## 3. Configure the normal `.env`

For a fresh installation, create `.env` if it is missing, then edit it:

```bash
if [ ! -f .env ]; then
    cp .env.example .env
fi
chmod 600 .env
nano .env
```

For this fresh installation, use the following complete contents. Replace `<RUNTIME_PASSWORD>` with the first generated value. Use the actual Pi IP and socket path found above. **Replace the contents rather than appending another database block.**

```dotenv
APP_NAME="מערכת נוכחות"
APP_ENV=local
APP_KEY=
APP_DEBUG=false
APP_URL=http://192.168.1.10:8000
APP_LOCALE=he
APP_FALLBACK_LOCALE=he
APP_FAKER_LOCALE=he_IL

DB_CONNECTION=mariadb
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=shift_management
DB_USERNAME=shift_app
DB_PASSWORD="<RUNTIME_PASSWORD>"
DB_SOCKET="/run/mysqld/mysqld.sock"

SESSION_DRIVER=database
SESSION_LIFETIME=43200
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=false
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

CACHE_STORE=file
QUEUE_CONNECTION=sync
LOG_CHANNEL=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=7
```

In nano, save with **Ctrl+O**, **Enter**, then exit with **Ctrl+X**.

Generate `APP_KEY` once for this new installation:

```bash
php artisan config:clear
php artisan key:generate
```

The command fills `APP_KEY` automatically. It is an encryption secret, independent of the computer's hardware. Preserve it when moving/restoring an existing installation; a fresh, independent installation gets its own key. Keep keys and passwords private, including in screenshots and chat.

## 4. Create `.env.migrate`

After generating the Pi's key, copy the complete `.env` into `.env.migrate` if that file is missing:

```bash
if [ ! -f .env.migrate ]; then
    cp .env .env.migrate
fi
chmod 600 .env.migrate
nano .env.migrate
```

Change only `DB_USERNAME` to `shift_migrate` and `DB_PASSWORD` to the **second generated password**. If the file already existed, ensure its other settings match the corrected Pi `.env`.

| Setting | `.env` | `.env.migrate` |
|---|---|---|
| `DB_DATABASE` | `shift_management` | `shift_management` |
| `DB_USERNAME` | `shift_app` | `shift_migrate` |
| `DB_PASSWORD` | Runtime password | Migration password |
| `DB_SOCKET` | Actual Pi socket | Same socket |
| `APP_KEY` | Generated Pi key | Same key |

`shift_migrate` is a **username**, not a database name. Each setting must appear once per file, including `DB_PASSWORD`. Laravel selects `.env.migrate` instead of merging it with `.env`; it needs a complete configuration.

Check the nonsecret database settings for duplicates:

```bash
grep -nE '^[[:space:]]*DB_(DATABASE|USERNAME|SOCKET)=' .env .env.migrate
```

Expect exactly three matches per file, with the values above. Remove old `shift_management_local`, `shift_local` and `.local/mariadb` settings. Do not paste full environment files into chat.

## 5. Create the tables and first manager

```bash
php artisan config:clear
php artisan migrate --env=migrate
php artisan app:create-manager
```

Migrations should finish with `DONE`. The manager command uses normal `.env` runtime credentials. Choose a username, name, gender and a hidden, confirmed password. The password must have at least 12 characters and at most 72 UTF-8 bytes. The command prints the username and login URL, never the password. Repeating it preserves an existing manager.

## 6. Run the site as a service

Stop any manually running Artisan server with **Ctrl+C** first.

For an existing installation, edit the Pi's normal `.env` with `nano ~/shift-management/.env`. Set `APP_URL` to the actual `http://PI_IP:8000` address (for example, `http://192.168.1.10:8000`) and `SESSION_SECURE_COOKIE=false` for this HTTP setup. Retain the existing key and database credentials.

Clear configuration and check the Linux username, project path and PHP executable:

```bash
cd ~/shift-management
php artisan config:clear
whoami
pwd
command -v php
chmod -R u+rwX storage bootstrap/cache
sudo nano /etc/systemd/system/shift-management.service
```

Paste the following service. Adjust `User`, `WorkingDirectory` and both executable/file paths in `ExecStart` if your results differ from the example:

```ini
[Unit]
Description=Shift management Laravel server
After=network.target mariadb.service
Wants=mariadb.service
StartLimitIntervalSec=0

[Service]
Type=simple
User=pi-enterb7
WorkingDirectory=/home/pi-enterb7/shift-management
ExecStart=/usr/bin/php /home/pi-enterb7/shift-management/artisan serve --host=0.0.0.0 --port=8000 --no-reload
Restart=always
RestartSec=5
KillMode=control-group
UMask=0077

[Install]
WantedBy=multi-user.target
```

**Keep `--host=0.0.0.0` for direct Wi-Fi access.** `127.0.0.1` would accept only connections from the Pi. Laravel reads normal `.env` itself; no systemd `EnvironmentFile` is needed.

Save the file, then enable startup at boot and start/restart the service:

```bash
sudo systemctl daemon-reload
sudo systemctl enable shift-management
sudo systemctl restart shift-management
sudo systemctl status shift-management --no-pager
```

Expect `enabled` and `active (running)`. Two PHP processes are normal. You can disconnect SSH: [systemd](https://www.freedesktop.org/software/systemd/man/latest/systemd.service.html#Restart=) keeps the service running and retries five seconds after an unexpected exit. An intentional stop stays stopped. This does not detect hangs or repair database failures.

## 7. Open and verify the site

From another device on the office Wi-Fi, open **http://192.168.1.10:8000/login**, using the Pi's actual IP. Use port **8000**, not port 80. `0.0.0.0` is a listening address, not a browser destination.

On the Pi, check the listener and Laravel response if needed:

```bash
sudo ss -ltnp '( sport = :8000 )'
curl -sS -o /dev/null -w 'Laravel HTTP status: %{http_code}\n' \
    --max-time 5 http://127.0.0.1:8000/login
```

Expect a listener on `0.0.0.0:8000` and HTTP `200`. Sign in using the manager credentials you chose; the manager should reach `/admin` and be able to create a worker. Finally, reboot the Pi when convenient and verify the service and Wi-Fi login again. These are manual checks to perform, not a claim that this guide verified them.

## Restarts, logs and troubleshooting

After editing `.env`, apply changes with:

```bash
cd ~/shift-management
php artisan config:clear
sudo systemctl restart shift-management
```

After editing the service file, run `sudo systemctl daemon-reload` before restarting it. `--no-reload` means the server does not automatically notice `.env` changes.

```bash
sudo systemctl status shift-management --no-pager
sudo journalctl -u shift-management -n 50 --no-pager
```

| Symptom | Check or fix |
|---|---|
| `local datadir is not initialized` | Use `sudo systemctl start mariadb`; the workspace database script is not this Pi's setup path. |
| SQL error `2002`, missing socket | Query `SELECT @@socket` again and correct `DB_SOCKET` in both environment files. |
| SQL error `1045`, access denied | Check the username/password mapping above. Changing `.env` does not change the password stored in MariaDB. |
| Wrong database/user despite edits | Remove duplicate settings in both files. Check `printenv DB_DATABASE DB_USERNAME DB_SOCKET` for shell overrides. If it prints stale values, run `unset DB_DATABASE DB_USERNAME DB_SOCKET`, then clear configuration again. |
| Local HTTP `200`, but Wi-Fi cannot connect | Confirm `0.0.0.0:8000`, the current Pi IP, and that devices can communicate on that Wi-Fi. If a firewall is enabled, allow TCP 8000 from the office LAN; keep router internet port forwarding off. |
| Apache default page or `404` on port 80 | Open the URL with `:8000/login`. Apache configuration does not control this service. |
| Service cannot start: address already in use | Stop the manually launched Artisan server, then restart the systemd service. |
| Login returns `500` | Inspect the service journal and private `storage/logs/` files locally. Keep `APP_DEBUG=false` and redact secrets before sharing errors. |

If placeholder text was used as a password, generate two new passwords and open the private MariaDB console from step 2. Replace the placeholders **before** running:

```sql
ALTER USER 'shift_app'@'localhost' IDENTIFIED BY '<NEW_RUNTIME_PASSWORD>';
ALTER USER 'shift_migrate'@'localhost' IDENTIFIED BY '<NEW_MIGRATION_PASSWORD>';
EXIT;
```

Update the matching environment-file passwords and restart the application. This [changes the existing accounts](https://mariadb.com/docs/server/reference/sql-statements/account-management-sql-statements/alter-user); database recreation is unnecessary.

For updates or moving the installation, preserve `.env`, `.env.migrate`, the original `APP_KEY` and private `storage/app/` uploads. Back up MariaDB and those files to protected off-device storage; follow the [backup and restore procedure](DEPLOYMENT.md#off-device-backup-and-restore-rehearsal). Ordinary restarts do not require recreating accounts, regenerating keys or rerunning installation steps.
