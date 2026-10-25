<?php

namespace Tests\Feature;

use App\Models\MonthlyReceipt;
use App\Models\User;
use App\Services\AccountManagementService;
use App\Services\WorkdayCsvReportService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesShiftRecords;
use Tests\Support\ConcurrentProcesses;
use Tests\TestCase;

class WorkdayCsvConcurrencyTest extends TestCase
{
    // Separate MariaDB connections must see committed fixtures during the race.
    use CreatesShiftRecords, DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('The MariaDB race requires PHP pcntl and local socket pairs.');
        }

        $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00', 'Asia/Jerusalem'));
    }

    #[DataProvider('operationOrders')]
    public function test_report_preparation_racing_manager_deactivation_either_finishes_while_authorized_or_rejects_before_output(bool $reportStartsFirst): void
    {
        $reportingManager = User::factory()->manager()->create();
        $otherManager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $shift = $this->createShiftRecord($worker, '2026-10-20');
        $receipt = MonthlyReceipt::create(['worker_id' => $worker->id, 'month' => '2026-09-01', 'has_receipts' => true]);
        MonthlyReceipt::create(['worker_id' => $worker->id, 'month' => '2026-10-01', 'has_receipts' => false]);
        $originalWorker = $worker->fresh()->getRawOriginal();
        $originalShift = $shift->fresh()->getRawOriginal();
        $originalReceipt = $receipt->fresh()->getRawOriginal();
        $operations = [
            'report' => function () use ($reportingManager): string {
                $paused = false;

                // Hold the actual manager row briefly once preparation locks
                // it, widening overlap without a production-only test hook.
                DB::listen(function (QueryExecuted $query) use (&$paused): void {
                    $sql = strtolower($query->sql);

                    if (! $paused && str_contains($sql, 'users') && str_contains($sql, 'for update')) {
                        $paused = true;
                        usleep(150_000);
                    }
                });

                $stream = null;
                $result = 'rejected';
                ob_start();

                try {
                    $stream = app(WorkdayCsvReportService::class)->prepare($reportingManager, '2026-10-20', '2026-10-22', '2026-09');
                    $result = 'prepared:'.base64_encode(stream_get_contents($stream));
                } catch (AuthorizationException) {
                    // Deactivation won the row lock; no CSV may escape.
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }

                    $output = ob_get_clean();
                }

                if ($output !== '') {
                    throw new RuntimeException('Report preparation unexpectedly wrote response output.');
                }

                return $result;
            },
            'deactivate' => function () use ($otherManager, $reportingManager): string {
                app(AccountManagementService::class)->deactivate($otherManager, $reportingManager);

                return 'deactivated';
            },
        ];

        if (! $reportStartsFirst) {
            $operations = array_reverse($operations, true);
        }

        $results = ConcurrentProcesses::run($operations);

        $this->assertSame('deactivated', $results['deactivate']);

        if ($results['report'] !== 'rejected') {
            $this->assertStringStartsWith('prepared:', $results['report']);
            $csv = base64_decode(substr($results['report'], strlen('prepared:')), true);
            $this->assertIsString($csv);
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
            $stream = fopen('php://temp', 'w+');
            fwrite($stream, substr($csv, 3));
            rewind($stream);
            $this->assertSame(['שם העובד', 'מספר ימי עבודה', 'תאריכי עבודה', 'קבלות'], fgetcsv($stream, null, ',', '"', ''));
            $this->assertSame([$worker->fullName(), '1', '20/10/2026', 'יש'], fgetcsv($stream, null, ',', '"', ''));
            $this->assertFalse(fgetcsv($stream, null, ',', '"', ''));
            fclose($stream);
        }

        $this->assertFalse($reportingManager->fresh()->is_active);
        $this->assertTrue($otherManager->fresh()->is_active);
        $this->assertSame($originalWorker, $worker->fresh()->getRawOriginal());
        $this->assertSame($originalShift, $shift->fresh()->getRawOriginal());
        $this->assertSame($originalReceipt, $receipt->fresh()->getRawOriginal());
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('shifts', 1);
        $this->assertDatabaseCount('monthly_receipts', 2);
    }

    public static function operationOrders(): array
    {
        return ['report process first' => [true], 'deactivation process first' => [false]];
    }
}
