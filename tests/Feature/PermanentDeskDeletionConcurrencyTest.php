<?php

namespace Tests\Feature;

use App\Models\Desk;
use App\Models\DeskReservation;
use App\Models\User;
use App\Services\DeskManagementService;
use App\Services\DeskReservationService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesDeskReservations;
use Tests\Support\ConcurrentProcesses;
use Tests\TestCase;

class PermanentDeskDeletionConcurrencyTest extends TestCase
{
    // Forked MariaDB connections must see committed setup rows.
    use CreatesDeskReservations, DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('The MariaDB races require PHP pcntl and local socket pairs.');
        }

        $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00:00', 'Asia/Jerusalem'));
    }

    #[DataProvider('operationOrders')]
    public function test_first_ever_booking_and_deletion_are_serialized_at_the_desk_lock(bool $deletionFirst): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();
        $delete = function () use ($manager, $desk): string {
            app(DeskManagementService::class)->delete($manager, $desk);

            return 'deleted';
        };
        $book = function () use ($worker, $desk): string {
            try {
                app(DeskReservationService::class)->book($worker, $desk->id, '2026-10-21', '09:00', '10:00');

                return 'booked';
            } catch (ValidationException) {
                return 'rejected';
            }
        };

        $results = $this->runInDeskLockOrder($delete, $book, $deletionFirst);

        $this->assertSame('deleted', $results['delete']);
        $this->assertSame($deletionFirst ? 'rejected' : 'booked', $results['worker']);
        $this->assertDatabaseMissing('desks', ['id' => $desk->id]);
        $this->assertDatabaseCount('desk_reservations', 0);
        $this->assertDatabaseCount('desks', 8);

        // A rejected booking rolls back its first-ever date insertion. A
        // committed booking leaves the date identity intact after deletion.
        if ($deletionFirst) {
            $this->assertDatabaseMissing('calendar_dates', ['date' => '2026-10-21']);
        } else {
            $this->assertDatabaseHas('calendar_dates', ['date' => '2026-10-21']);
        }
    }

    #[DataProvider('operationOrders')]
    public function test_deletion_racing_a_move_out_of_the_desk_preserves_only_a_committed_replacement(bool $deletionFirst): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $desks = Desk::query()->take(2)->get();
        $reservation = $this->createDeskReservation($worker, $desks[0]);
        $delete = function () use ($manager, $desks): string {
            app(DeskManagementService::class)->delete($manager, $desks[0]);

            return 'deleted';
        };
        $edit = function () use ($worker, $desks, $reservation): string {
            try {
                app(DeskReservationService::class)->update($worker, $reservation, $desks[1]->id, '13:00', '14:00');

                return 'moved';
            } catch (ValidationException|ModelNotFoundException) {
                return 'rejected';
            }
        };

        $results = $this->runInDeskLockOrder($delete, $edit, $deletionFirst);

        $this->assertSame('deleted', $results['delete']);
        $this->assertSame($deletionFirst ? 'rejected' : 'moved', $results['worker']);
        $this->assertDatabaseMissing('desks', ['id' => $desks[0]->id]);
        $this->assertDatabaseMissing('desk_reservations', ['id' => $reservation->id]);
        $this->assertDatabaseHas('desks', ['id' => $desks[1]->id, 'is_active' => true]);
        $this->assertDatabaseCount('desk_reservations', $deletionFirst ? 0 : 1);

        if (! $deletionFirst) {
            $replacement = DeskReservation::active()->sole();
            $this->assertSame($desks[1]->id, $replacement->desk_id);
            $this->assertSame($worker->id, $replacement->worker_id);
        }
    }

    #[DataProvider('operationOrders')]
    public function test_deletion_racing_a_move_into_the_desk_never_leaves_a_dangling_reservation(bool $deletionFirst): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $desks = Desk::query()->take(2)->get();
        $reservation = $this->createDeskReservation($worker, $desks[1]);
        $delete = function () use ($manager, $desks): string {
            app(DeskManagementService::class)->delete($manager, $desks[0]);

            return 'deleted';
        };
        $edit = function () use ($worker, $desks, $reservation): string {
            try {
                app(DeskReservationService::class)->update($worker, $reservation, $desks[0]->id, '13:00', '14:00');

                return 'moved';
            } catch (ValidationException|ModelNotFoundException) {
                return 'rejected';
            }
        };

        $results = $this->runInDeskLockOrder($delete, $edit, $deletionFirst);

        $this->assertSame('deleted', $results['delete']);
        $this->assertSame($deletionFirst ? 'rejected' : 'moved', $results['worker']);
        $this->assertDatabaseMissing('desks', ['id' => $desks[0]->id]);
        $this->assertDatabaseMissing('desk_reservations', ['desk_id' => $desks[0]->id]);
        $this->assertDatabaseCount('desk_reservations', 1);
        $this->assertSame($deletionFirst, $reservation->fresh()->cancelled_at === null);
        $this->assertSame($desks[1]->id, $reservation->fresh()->desk_id);
    }

    #[DataProvider('operationOrders')]
    public function test_deletion_racing_worker_cancellation_removes_the_reservation_in_either_order(bool $deletionFirst): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();
        $reservation = $this->createDeskReservation($worker, $desk);
        $delete = function () use ($manager, $desk): string {
            app(DeskManagementService::class)->delete($manager, $desk);

            return 'deleted';
        };
        $cancel = function () use ($worker, $reservation): string {
            try {
                app(DeskReservationService::class)->cancel($worker, $reservation);

                return 'cancelled';
            } catch (ValidationException|ModelNotFoundException) {
                return 'rejected';
            }
        };

        $results = $this->runInDeskLockOrder($delete, $cancel, $deletionFirst);

        $this->assertSame('deleted', $results['delete']);
        $this->assertSame($deletionFirst ? 'rejected' : 'cancelled', $results['worker']);
        $this->assertDatabaseMissing('desks', ['id' => $desk->id]);
        $this->assertDatabaseCount('desk_reservations', 0);
        $this->assertDatabaseHas('calendar_dates', ['date' => '2026-10-21']);
    }

    public static function operationOrders(): array
    {
        return ['delete holds desk first' => [true], 'worker holds desk first' => [false]];
    }

    /**
     * Start the second operation only after the first owns a real desk lock.
     * Socket signals make both outcomes deterministic instead of relying on
     * process launch order or on an arbitrary sleep before starting the race.
     * Fixtures are committed; ConcurrentProcesses supplies separate connections.
     *
     * @param  Closure(): string  $delete
     * @param  Closure(): string  $worker
     * @return array<string, string>
     */
    private function runInDeskLockOrder(Closure $delete, Closure $worker, bool $deletionFirst): array
    {
        $signals = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($signals === false) {
            throw new RuntimeException('Could not create the desk-lock-order socket pair.');
        }

        foreach ($signals as $signal) {
            stream_set_timeout($signal, 10);
        }

        $first = $deletionFirst ? $delete : $worker;
        $second = $deletionFirst ? $worker : $delete;
        $firstName = $deletionFirst ? 'delete' : 'worker';
        $secondName = $deletionFirst ? 'worker' : 'delete';

        try {
            return ConcurrentProcesses::run([
                $firstName => function () use ($first, $signals): string {
                    $signalled = false;

                    DB::listen(function (QueryExecuted $query) use (&$signalled, $signals): void {
                        $sql = strtolower($query->sql);

                        if ($signalled || ! str_contains($sql, '`desks`') || ! str_contains($sql, 'for update')) {
                            return;
                        }

                        $signalled = true;
                        fwrite($signals[0], "locked\n");

                        if (fgets($signals[0]) !== "started\n") {
                            throw new RuntimeException('The second operation did not reach the desk-lock barrier.');
                        }

                        // The second operation now runs against the held lock.
                        usleep(150_000);
                    });

                    return $first();
                },
                $secondName => function () use ($second, $signals): string {
                    if (fgets($signals[1]) !== "locked\n") {
                        throw new RuntimeException('The first operation did not acquire its desk lock.');
                    }

                    fwrite($signals[1], "started\n");

                    return $second();
                },
            ]);
        } finally {
            fclose($signals[0]);
            fclose($signals[1]);
        }
    }
}
