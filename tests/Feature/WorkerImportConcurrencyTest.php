<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountManagementService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConcurrentProcesses;
use Tests\TestCase;

class WorkerImportConcurrencyTest extends TestCase
{
    // Forked MariaDB connections must see committed fixtures, not an enclosing
    // RefreshDatabase transaction belonging only to the parent process.
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('The MariaDB import races require PHP pcntl and local socket pairs.');
        }
    }

    #[DataProvider('processOrders')]
    public function test_concurrent_imports_create_one_worker_and_skip_the_existing_username(bool $reverseProcesses): void
    {
        $attributes = $this->workerAttributes();
        $operations = [
            'first-import' => function () use ($attributes): string {
                $this->pauseAfterAccountGate();

                return json_encode(app(AccountManagementService::class)->importWorkers([$attributes]));
            },
            'second-import' => function () use ($attributes): string {
                $this->pauseAfterAccountGate();

                return json_encode(app(AccountManagementService::class)->importWorkers([$attributes]));
            },
        ];

        if ($reverseProcesses) {
            $operations = array_reverse($operations, true);
        }

        $results = ConcurrentProcesses::run($operations);

        $first = json_decode($results['first-import'], true);
        $second = json_decode($results['second-import'], true);
        $createdCounts = [$first['created'], $second['created']];
        sort($createdCounts);
        $this->assertSame([0, 1], $createdCounts);
        $this->assertSame(1, count($first['skipped']) + count($second['skipped']));
        $this->assertSame([$attributes['username']], [...$first['skipped'], ...$second['skipped']]);
        $this->assertDatabaseCount('users', 1);
        $worker = User::query()->sole();
        $this->assertSame($attributes['username'], $worker->username);
        $this->assertSame('worker', $worker->role);
        $this->assertTrue($worker->is_active);
        $this->assertTrue(Hash::check($attributes['password'], $worker->password));
    }

    #[DataProvider('processOrders')]
    public function test_import_racing_manager_creation_preserves_the_account_created_first(bool $reverseProcesses): void
    {
        $manager = User::factory()->manager()->create();
        $originalManager = $manager->fresh()->getRawOriginal();
        $importAttributes = $this->workerAttributes();
        $managerAttributes = array_replace($importAttributes, [
            'role' => 'manager', 'password' => 'Manager-chosen-test-password-84',
        ]);
        $operations = [
            'import' => function () use ($importAttributes): string {
                $this->pauseAfterAccountGate();

                return json_encode(app(AccountManagementService::class)->importWorkers([$importAttributes]));
            },
            'manager-create' => function () use ($manager, $managerAttributes): string {
                $this->pauseAfterAccountGate();

                try {
                    app(AccountManagementService::class)->create($manager, $managerAttributes);

                    return 'created';
                } catch (ValidationException) {
                    return 'username-already-exists';
                }
            },
        ];

        if ($reverseProcesses) {
            $operations = array_reverse($operations, true);
        }

        $results = ConcurrentProcesses::run($operations);

        $import = json_decode($results['import'], true);
        $account = User::where('username', $importAttributes['username'])->sole();

        if ($import['created'] === 1) {
            $this->assertSame([], $import['skipped']);
            $this->assertSame('username-already-exists', $results['manager-create']);
            $this->assertSame('worker', $account->role);
            $this->assertTrue(Hash::check($importAttributes['password'], $account->password));
            $this->assertFalse(Hash::check($managerAttributes['password'], $account->password));
        } else {
            $this->assertSame(0, $import['created']);
            $this->assertSame([$importAttributes['username']], $import['skipped']);
            $this->assertSame('created', $results['manager-create']);
            $this->assertSame('manager', $account->role);
            $this->assertTrue(Hash::check($managerAttributes['password'], $account->password));
            $this->assertFalse(Hash::check($importAttributes['password'], $account->password));
        }

        $this->assertTrue($account->is_active);
        $this->assertDatabaseCount('users', 2);
        $this->assertSame($originalManager, $manager->fresh()->getRawOriginal());
    }

    public static function processOrders(): array
    {
        return ['original process order' => [false], 'reversed process order' => [true]];
    }

    /** Inputs have already passed CSV validation and username normalization. */
    private function workerAttributes(): array
    {
        return [
            'username' => 'same.worker', 'first_name' => 'דוד', 'last_name' => 'כהן',
            'gender' => 'male', 'birth_date' => null, 'role' => 'worker',
            'password' => 'Imported-test-password-93',
        ];
    }

    /** Hold the real shared account lock briefly so both processes overlap. */
    private function pauseAfterAccountGate(): void
    {
        $paused = false;

        DB::listen(function (QueryExecuted $query) use (&$paused): void {
            if (! $paused && str_contains(strtolower($query->sql), 'get_lock(')) {
                $paused = true;
                usleep(150_000);
            }
        });
    }
}
