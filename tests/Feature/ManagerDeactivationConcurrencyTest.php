<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class ManagerDeactivationConcurrencyTest extends TestCase
{
    // Committed fixtures are necessary: each racing process uses its own MariaDB connection.
    use DatabaseMigrations;

    #[DataProvider('deactivationTargets')]
    public function test_simultaneous_manager_deactivations_leave_one_active_manager(bool $crossDeactivate): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('The MariaDB race test requires PHP pcntl and local socket pairs.');
        }

        $firstManager = User::factory()->manager()->create();
        $secondManager = User::factory()->manager()->create();
        $managerIds = [$firstManager->id, $secondManager->id];
        $processes = [];

        // Never share a live PDO socket across forked processes.
        DB::disconnect();

        foreach ($managerIds as $index => $actorId) {
            $targetId = $crossDeactivate ? $managerIds[1 - $index] : $actorId;
            $processes[] = $this->startAccountMutationProcess('deactivate', $actorId, $targetId);
        }

        foreach ($processes as $process) {
            $this->assertSame("ready\n", fgets($process['socket']), 'A child did not reach the race barrier.');
        }

        // Both children loaded their actor before either request can acquire the service lock.
        foreach ($processes as $process) {
            fwrite($process['socket'], "start\n");
        }

        $results = [];

        foreach ($processes as $process) {
            $results[] = trim((string) fgets($process['socket']));
            fclose($process['socket']);
            pcntl_waitpid($process['pid'], $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        DB::purge();
        sort($results);
        $this->assertSame(['deactivated', 'rejected'], $results);
        $this->assertSame(1, User::query()->where('role', 'manager')->where('is_active', true)->count());
        $this->assertDatabaseCount('users', 2);
    }

    public static function deactivationTargets(): array
    {
        return [
            'each manager deactivates themselves' => [false],
            'managers deactivate one another using stale actor models' => [true],
        ];
    }

    public function test_simultaneous_first_manager_setups_create_only_one_manager(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('The MariaDB race test requires PHP pcntl and local socket pairs.');
        }

        // There is no existing manager row to lock. Bootstrap must serialize that absent-row case.
        $this->assertDatabaseCount('users', 0);
        DB::disconnect();
        $processes = [
            $this->startAccountMutationProcess('bootstrap'),
            $this->startAccountMutationProcess('bootstrap'),
        ];

        foreach ($processes as $process) {
            $this->assertSame("ready\n", fgets($process['socket']));
        }

        foreach ($processes as $process) {
            fwrite($process['socket'], "start\n");
        }

        $results = [];

        foreach ($processes as $process) {
            $results[] = trim((string) fgets($process['socket']));
            fclose($process['socket']);
            pcntl_waitpid($process['pid'], $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        DB::purge();
        sort($results);
        $this->assertSame(['already-exists', 'created'], $results);
        $this->assertDatabaseCount('users', 1);
        $this->assertTrue(User::query()->sole()->isManager());
    }

    /**
     * Start one independent connection, wait at a shared barrier, then mutate.
     * Expected business rejections are reported separately from unexpected failures.
     *
     * @return array{pid: int, socket: resource}
     */
    private function startAccountMutationProcess(string $operation, ?int $actorId = null, ?int $targetId = null): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw new RuntimeException('Could not create the concurrency-test barrier.');
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
            $actor = null;
            $target = null;

            if ($operation === 'deactivate') {
                $actor = User::query()->findOrFail($actorId);
                $target = User::query()->findOrFail($targetId);
            }

            fwrite($socket, "ready\n");

            if (fgets($socket) !== "start\n") {
                throw new RuntimeException('The race barrier was not released.');
            }

            try {
                $service = app(AccountManagementService::class);

                if ($operation === 'bootstrap') {
                    $manager = $service->bootstrap([
                        'username' => 'bootstrap.manager.'.getmypid(),
                        'first_name' => 'First',
                        'last_name' => 'Manager',
                        'gender' => 'other',
                        'birth_date' => null,
                        'password' => 'Chosen-test-password-93',
                    ]);
                    fwrite($socket, $manager === null ? "already-exists\n" : "created\n");
                } else {
                    $service->deactivate($actor, $target);
                    fwrite($socket, "deactivated\n");
                }
            } catch (AuthorizationException|ValidationException) {
                fwrite($socket, "rejected\n");
            }

            DB::disconnect();
            fclose($socket);
            exit(0);
        } catch (Throwable $exception) {
            fwrite($socket, 'unexpected: '.get_class($exception)."\n");
            fclose($socket);
            exit(1);
        }
    }
}
