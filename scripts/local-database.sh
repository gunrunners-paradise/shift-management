#!/usr/bin/env bash

# Control an already initialized, user-owned development MariaDB instance.
# Initialization, credentials, migrations, and manager setup are separate steps;
# this script never creates, deletes, or resets database data or reads .env files.
set -euo pipefail
umask 077

script_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
project_directory="$(cd -- "$script_directory/.." && pwd)"
runtime_directory="$project_directory/.local/mariadb"
data_directory="$runtime_directory/data"
socket_file="$runtime_directory/mariadb.sock"
pid_file="$runtime_directory/mariadb.pid"
server_log="$runtime_directory/server.log"
control_lock="$runtime_directory/control.lock"
database_user="$(id -un)"

server_state='stopped'
server_pid=''
state_problem=''
readiness_problem=''

fail() {
    printf 'Error: %s\n' "$1" >&2
    exit 1
}

if [[ $# -ne 1 || ! "$1" =~ ^(start|stop|status)$ ]]; then
    printf 'Usage: bash scripts/local-database.sh {start|stop|status}\n' >&2
    exit 2
fi

for required_command in mariadbd mariadb mariadb-admin flock timeout readlink nohup; do
    command -v "$required_command" >/dev/null 2>&1 || fail "Required command is unavailable: $required_command"
done

[[ -d "$data_directory/mysql" && -f "$data_directory/ibdata1" ]] || fail \
    "The local datadir is not initialized: $data_directory. Follow the local database setup instructions; existing data will not be changed."
[[ -O "$runtime_directory" && -O "$data_directory" ]] || fail \
    'Run this script as the operating-system user who owns the local database directories.'

for protected_path in "$runtime_directory" "$data_directory" "$socket_file" "$pid_file" "$server_log" "$control_lock"; do
    [[ ! -L "$protected_path" ]] || fail "Refusing a symbolic link in the local database runtime: $protected_path"
done

# Serialize competing start/stop commands. The launched server closes this file
# descriptor so it cannot accidentally retain the lifecycle lock indefinitely.
exec 9>"$control_lock"
flock --wait 10 9 || fail 'Another local database command is still running. Try again shortly.'

# An authenticated query verifies readiness and the actual datadir behind the
# socket. mysqladmin-style ping alone can succeed even when access is denied.
# timeout also bounds reads if a socket accepts connections but stops responding.
database_is_ready() {
    local reported_data_directory
    readiness_problem=''

    if ! reported_data_directory="$(timeout --foreground 2s mariadb \
        --no-defaults --protocol=socket --socket="$socket_file" \
        --user="$database_user" --connect-timeout=2 --batch --skip-column-names \
        --execute='SELECT @@datadir' 2>/dev/null)"; then
        return 1
    fi

    if [[ "${reported_data_directory%/}" != "$data_directory" ]]; then
        readiness_problem='The socket belongs to a different MariaDB datadir. No server will be started or stopped.'
        return 1
    fi

    return 0
}

# A PID file can survive a crash, and the operating system may reuse its PID.
# Inspect the executable, owner, and explicit runtime arguments before trusting
# a live PID; never signal a process just because its number appears in a file.
process_matches_local_database() {
    local executable argument
    local has_data_directory=false
    local has_socket_file=false
    local has_pid_file=false

    [[ -O "/proc/$server_pid" ]] || return 1
    executable="$(readlink -- "/proc/$server_pid/exe" 2>/dev/null)" || return 1

    case "$executable" in
        */mariadbd|*/mysqld) ;;
        *) return 1 ;;
    esac

    while IFS= read -r -d '' argument; do
        case "$argument" in
            "--datadir=$data_directory") has_data_directory=true ;;
            "--socket=$socket_file") has_socket_file=true ;;
            "--pid-file=$pid_file") has_pid_file=true ;;
        esac
    done < "/proc/$server_pid/cmdline"

    [[ "$has_data_directory" == true && "$has_socket_file" == true && "$has_pid_file" == true ]]
}

inspect_server() {
    server_state='stopped'
    server_pid=''
    state_problem=''

    if [[ -e "$pid_file" ]]; then
        if [[ ! -f "$pid_file" ]]; then
            server_state='unsafe'
            state_problem='The PID path is not a regular file.'
            return
        fi

        IFS= read -r server_pid < "$pid_file" || true

        if [[ ! "$server_pid" =~ ^[1-9][0-9]*$ ]]; then
            server_state='unsafe'
            state_problem='The local PID file does not contain a valid process ID.'
            return
        fi

        if [[ -d "/proc/$server_pid" ]]; then
            if ! process_matches_local_database; then
                server_state='unsafe'
                state_problem='The PID file refers to an unrelated or unverifiable process. No server will be started or stopped.'
                return
            fi

            if database_is_ready; then
                server_state='running'
            elif [[ -n "$readiness_problem" ]]; then
                server_state='unsafe'
                state_problem="$readiness_problem"
            else
                server_state='starting'
            fi

            return
        fi
        # A dead PID is stale. Leave its file in place: MariaDB owns that file
        # and can replace it when starting successfully against the same data.
    fi

    if database_is_ready || [[ -n "$readiness_problem" ]]; then
        server_state='unsafe'
        state_problem='The socket responds without a matching live local PID. No server will be started or stopped.'
    fi
}

wait_until_ready() {
    local deadline=$((SECONDS + 12))

    while (( SECONDS < deadline )); do
        inspect_server

        case "$server_state" in
            running) return 0 ;;
            unsafe) fail "$state_problem" ;;
        esac

        sleep 0.2
    done

    fail "MariaDB did not become ready within 12 seconds. Check $server_log and confirm that socket authentication is configured for the current operating-system user."
}

start_database() {
    inspect_server

    case "$server_state" in
        running)
            printf 'Local MariaDB is already running (PID %s).\nSocket: %s\n' "$server_pid" "$socket_file"
            return
            ;;
        unsafe) fail "$state_problem" ;;
        stopped)
            # nohup detaches terminal hangup handling; the private log receives
            # startup output. No network port or system service is opened.
            touch "$server_log"
            chmod 600 "$server_log"
            nohup mariadbd --no-defaults \
                --datadir="$data_directory" --socket="$socket_file" \
                --pid-file="$pid_file" --log-error="$server_log" \
                --skip-networking --skip-log-bin \
                --innodb-buffer-pool-size=64M --innodb-log-file-size=32M \
                --max-connections=30 --performance-schema=OFF \
                </dev/null >>"$server_log" 2>&1 9>&- &
            ;;
        # If an owned server is already starting, wait for it instead of
        # launching a second process against the same database files.
        starting) ;;
    esac

    wait_until_ready
    printf 'Local MariaDB is running (PID %s).\nSocket: %s\n' "$server_pid" "$socket_file"
}

stop_database() {
    inspect_server

    case "$server_state" in
        stopped)
            printf 'Local MariaDB is already stopped. Persistent data remains at %s\n' "$data_directory"
            return
            ;;
        unsafe) fail "$state_problem" ;;
        starting) fail 'The local server is not ready for authenticated shutdown. Retry after startup or inspect the private server log.' ;;
    esac

    # Ask the verified local instance to flush and shut down normally. Never
    # force-kill a process or remove MariaDB's socket/PID files ourselves.
    if ! timeout --foreground 12s mariadb-admin --no-defaults \
        --protocol=socket --socket="$socket_file" --user="$database_user" \
        --connect-timeout=2 --shutdown-timeout=10 shutdown >/dev/null 2>&1; then
        fail "Graceful shutdown did not complete. Check $server_log; no process was force-stopped."
    fi

    inspect_server
    [[ "$server_state" == stopped ]] || fail 'The local database has not fully stopped. Check its status before retrying.'
    printf 'Local MariaDB stopped. Persistent data remains at %s\n' "$data_directory"
}

show_status() {
    inspect_server

    case "$server_state" in
        running)
            printf 'Local MariaDB is running (PID %s).\nSocket: %s\nData: %s\n' "$server_pid" "$socket_file" "$data_directory"
            ;;
        unsafe) fail "$state_problem" ;;
        *)
            printf 'Local MariaDB is stopped or not ready.\nData: %s\nStart: bash scripts/local-database.sh start\n' "$data_directory"
            return 1
            ;;
    esac
}

case "$1" in
    start) start_database ;;
    stop) stop_database ;;
    status) show_status ;;
esac
