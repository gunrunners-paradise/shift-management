<?php

namespace Tests\Support;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ConcurrentProcesses
{
    /**
     * Release independent MariaDB connections together and collect every result.
     * The caller must use committed fixtures, never RefreshDatabase transactions.
     * Short pauses after acquiring a real date lock widen overlap without adding
     * test hooks or changing the production locking order.
     *
     * @param  array<string, Closure(): string>  $operations
     * @return array<string, string>
     */
    public static function run(array $operations): array
    {
        $processes = [];
        DB::disconnect();

        foreach ($operations as $name => $operation) {
            $processes[$name] = self::start($operation);
        }

        $failures = [];

        foreach ($processes as $name => $process) {
            if (fgets($process['socket']) !== "ready\n") {
                $failures[] = $name.' did not reach the concurrency barrier.';
            }
        }

        foreach ($processes as $process) {
            fwrite($process['socket'], "start\n");
        }

        $results = [];

        foreach ($processes as $name => $process) {
            $response = json_decode((string) fgets($process['socket']), true);
            fclose($process['socket']);
            pcntl_waitpid($process['pid'], $status);

            if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0 || ! is_array($response)) {
                $error = $response['error'] ?? 'missing result or abnormal process exit';
                $failures[] = $name.': '.$error;
            } else {
                $results[$name] = $response['result'];
            }
        }

        DB::purge();

        if ($failures !== []) {
            throw new RuntimeException(implode(' ', $failures));
        }

        return $results;
    }

    /** @return array{pid: int, socket: resource} */
    private static function start(Closure $operation): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw new RuntimeException('Could not create the concurrency-test socket pair.');
        }

        $processId = pcntl_fork();

        if ($processId === -1) {
            throw new RuntimeException('Could not fork the MariaDB concurrency-test process.');
        }

        if ($processId > 0) {
            fclose($sockets[1]);
            stream_set_timeout($sockets[0], 20);

            return ['pid' => $processId, 'socket' => $sockets[0]];
        }

        fclose($sockets[0]);
        $socket = $sockets[1];
        stream_set_timeout($socket, 20);

        try {
            DB::purge();
            self::pauseAfterFirstDateLock();
            fwrite($socket, "ready\n");

            if (fgets($socket) !== "start\n") {
                throw new RuntimeException('The concurrency barrier was not released.');
            }

            $result = $operation();
            fwrite($socket, json_encode(['result' => $result])."\n");
            DB::disconnect();
            fclose($socket);
            exit(0);
        } catch (Throwable $exception) {
            // Exception classes distinguish unexpected SQL failures from the
            // explicitly handled business rejections in each test operation.
            fwrite($socket, json_encode(['error' => $exception::class])."\n");
            fclose($socket);
            exit(1);
        }
    }

    private static function pauseAfterFirstDateLock(): void
    {
        $paused = false;

        DB::listen(function (QueryExecuted $query) use (&$paused): void {
            $sql = strtolower($query->sql);

            if ($paused || ! str_contains($sql, 'for update')) {
                return;
            }

            if (str_contains($sql, 'calendar_dates')) {
                $paused = true;
                usleep(150_000);
            }
        });
    }
}
