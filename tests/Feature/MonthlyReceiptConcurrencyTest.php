<?php

namespace Tests\Feature;

use App\Models\MonthlyReceipt;
use App\Models\User;
use App\Services\AccountManagementService;
use App\Services\MonthlyReceiptService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\ConcurrentProcesses;
use Tests\TestCase;

class MonthlyReceiptConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('The MariaDB races require PHP pcntl and local socket pairs.');
        }

        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Jerusalem'));
    }

    public function test_simultaneous_first_submissions_create_one_worker_month_record(): void
    {
        $worker = User::factory()->create();
        $this->assertDatabaseCount('monthly_receipts', 0);

        $results = ConcurrentProcesses::run([
            'first-browser' => function () use ($worker): string {
                $receipt = app(MonthlyReceiptService::class)->set($worker, '2026-10', true);

                return 'saved:'.$receipt->id;
            },
            'second-browser' => function () use ($worker): string {
                $receipt = app(MonthlyReceiptService::class)->set($worker, '2026-10', true);

                return 'saved:'.$receipt->id;
            },
        ]);

        $this->assertSame($results['first-browser'], $results['second-browser']);
        $this->assertStringStartsWith('saved:', $results['first-browser']);
        $receipt = MonthlyReceipt::query()->sole();
        $this->assertSame($worker->id, $receipt->worker_id);
        $this->assertSame('2026-10-01', $receipt->month->format('Y-m-d'));
        $this->assertTrue($receipt->has_receipts);
    }

    public function test_deactivation_racing_a_receipt_write_rejects_later_writes_without_erasing_earlier_history(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();

        $results = ConcurrentProcesses::run([
            'receipts' => function () use ($worker): string {
                try {
                    app(MonthlyReceiptService::class)->set($worker, '2026-10', true);

                    return 'saved';
                } catch (AuthorizationException) {
                    return 'rejected';
                }
            },
            'deactivate' => function () use ($manager, $worker): string {
                app(AccountManagementService::class)->deactivate($manager, $worker);

                return 'deactivated';
            },
        ]);

        $this->assertSame('deactivated', $results['deactivate']);
        $this->assertContains($results['receipts'], ['saved', 'rejected']);
        $this->assertFalse($worker->fresh()->is_active);

        if ($results['receipts'] === 'saved') {
            $this->assertDatabaseHas('monthly_receipts', ['worker_id' => $worker->id, 'has_receipts' => true]);
            $this->assertDatabaseCount('monthly_receipts', 1);
        } else {
            $this->assertDatabaseCount('monthly_receipts', 0);
        }
    }
}
