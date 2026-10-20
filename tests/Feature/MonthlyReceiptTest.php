<?php

namespace Tests\Feature;

use App\Models\MonthlyReceipt;
use App\Models\User;
use App\Services\AccountManagementService;
use App\Services\MonthlyReceiptService;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MonthlyReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Jerusalem'));
    }

    public function test_missing_receipt_record_renders_an_unchecked_current_month_checkbox_below_the_calendar(): void
    {
        $worker = User::factory()->create();

        $response = $this->actingAs($worker)->get(route('worker.home', ['week' => '2026-11-22']));

        $response->assertOk()->assertSee('יש לי קבלות החודש');
        $document = $this->document($response->getContent());
        $this->assertCount(1, $document->query('//table[contains(@class,"shift-calendar")]/following::*[@id="worker-receipts"]'));
        $this->assertCount(1, $document->query('//*[@id="worker-receipts"]//input[@name="month" and @value="2026-10"]'));
        $this->assertCount(1, $document->query('//*[@id="worker-receipts"]//input[@type="checkbox" and @name="has_receipts" and not(@checked)]'));
        $this->assertCount(1, $document->query('//*[@id="worker-receipts"]//input[@type="hidden" and @name="has_receipts" and @value="0"]'));
        $this->assertDatabaseCount('monthly_receipts', 0);
    }

    public function test_worker_can_toggle_a_boolean_receipt_state_without_creating_duplicate_month_records(): void
    {
        $worker = User::factory()->create();
        $this->actingAs($worker);

        $this->put(route('worker.receipts.update'), ['month' => '2026-10', 'has_receipts' => '1'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $receipt = MonthlyReceipt::query()->sole();
        $this->assertTrue($receipt->has_receipts);
        $this->assertSame($worker->id, $receipt->worker_id);
        $this->assertSame('2026-10-01', $receipt->month->format('Y-m-d'));

        $this->put(route('worker.receipts.update'), ['month' => '2026-10', 'has_receipts' => '0'])
            ->assertSessionHasNoErrors();
        $this->put(route('worker.receipts.update'), ['month' => '2026-10', 'has_receipts' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($receipt->fresh()->has_receipts);
        $this->assertSame($receipt->id, MonthlyReceipt::query()->sole()->id);
        $this->assertDatabaseCount('monthly_receipts', 1);
    }

    #[DataProvider('invalidReceiptStates')]
    public function test_receipts_have_no_unanswered_or_non_boolean_third_state(mixed $value): void
    {
        $worker = User::factory()->create();

        $this->actingAs($worker)->put(route('worker.receipts.update'), ['month' => '2026-10', 'has_receipts' => $value])
            ->assertSessionHasErrors('has_receipts');

        $this->assertDatabaseCount('monthly_receipts', 0);
    }

    public static function invalidReceiptStates(): array
    {
        return [
            'unanswered' => [null],
            'empty' => [''],
            'yes text' => ['yes'],
            'true text' => ['true'],
            'number outside boolean values' => [2],
            'array' => [[1]],
        ];
    }

    public function test_array_receipt_input_redisplays_validation_feedback_without_a_server_error(): void
    {
        $worker = User::factory()->create();

        $response = $this->from(route('worker.home'))->actingAs($worker)->put(route('worker.receipts.update'), [
            'month' => '2026-10', 'has_receipts' => ['invalid input'],
        ]);

        $response->assertSessionHasErrors('has_receipts');
        $message = session('errors')->first('has_receipts');

        // Carry the response's session into the next request, as a browser does.
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->followRedirects($response)->assertOk()->assertSee($message);
        $this->assertDatabaseCount('monthly_receipts', 0);
    }

    #[DataProvider('invalidMonths')]
    public function test_worker_cannot_edit_previous_future_or_invalid_months(mixed $month): void
    {
        $worker = User::factory()->create();

        $this->actingAs($worker)->put(route('worker.receipts.update'), ['month' => $month, 'has_receipts' => '1'])
            ->assertSessionHasErrors('month');

        $this->assertDatabaseCount('monthly_receipts', 0);
    }

    public static function invalidMonths(): array
    {
        return [
            'previous month' => ['2026-09'],
            'next month' => ['2026-11'],
            'date instead of month' => ['2026-10-01'],
            'impossible month' => ['2026-13'],
            'missing month' => [null],
        ];
    }

    public function test_forged_ownership_fields_cannot_change_another_workers_receipt_state(): void
    {
        $worker = User::factory()->create();
        $otherWorker = User::factory()->create();
        $otherReceipt = $this->createReceipt($otherWorker, '2026-10', true);

        foreach (['worker_id', 'user_id'] as $field) {
            $this->actingAs($worker)->put(route('worker.receipts.update'), [
                'month' => '2026-10', 'has_receipts' => '0', $field => $otherWorker->id,
            ])->assertSessionHasErrors($field);
        }

        $this->assertTrue($otherReceipt->fresh()->has_receipts);
        $this->assertDatabaseCount('monthly_receipts', 1);
    }

    public function test_each_worker_reads_and_writes_only_their_own_monthly_checkbox(): void
    {
        $firstWorker = User::factory()->create();
        $secondWorker = User::factory()->create();
        $this->actingAs($firstWorker)->put(route('worker.receipts.update'), ['month' => '2026-10', 'has_receipts' => '1'])
            ->assertSessionHasNoErrors();
        $this->flushSession();
        $this->actingAs($secondWorker)->put(route('worker.receipts.update'), ['month' => '2026-10', 'has_receipts' => '0'])
            ->assertSessionHasNoErrors();

        $response = $this->get(route('worker.home', ['worker_id' => $firstWorker->id]));

        $response->assertOk();
        $document = $this->document($response->getContent());
        $this->assertCount(1, $document->query('//*[@id="worker-receipts"]//input[@type="checkbox" and not(@checked)]'));
        $this->assertDatabaseHas('monthly_receipts', ['worker_id' => $firstWorker->id, 'has_receipts' => true]);
        $this->assertDatabaseHas('monthly_receipts', ['worker_id' => $secondWorker->id, 'has_receipts' => false]);
    }

    public function test_guest_manager_and_deactivated_worker_cannot_submit_worker_receipts(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $attributes = ['month' => '2026-10', 'has_receipts' => '1'];
        $this->put(route('worker.receipts.update'), $attributes)->assertRedirect(route('login'));
        $this->actingAs($manager)->put(route('worker.receipts.update'), $attributes)->assertForbidden();
        $this->flushSession();
        $this->actingAs($worker);
        User::query()->whereKey($worker->id)->update(['is_active' => false]);

        $this->put(route('worker.receipts.update'), $attributes)->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('monthly_receipts', 0);
    }

    public function test_month_rollover_starts_unchecked_without_overwriting_the_previous_month(): void
    {
        $worker = User::factory()->create();
        $october = app(MonthlyReceiptService::class)->set($worker, '2026-10', true);
        $this->travelTo(CarbonImmutable::parse('2026-11-01 00:01:00', 'Asia/Jerusalem'));

        $response = $this->actingAs($worker)->get(route('worker.home', ['week' => '2026-10-18']));

        $response->assertOk();
        $document = $this->document($response->getContent());
        $this->assertCount(1, $document->query('//*[@id="worker-receipts"]//input[@name="month" and @value="2026-11"]'));
        $this->assertCount(1, $document->query('//*[@id="worker-receipts"]//input[@type="checkbox" and not(@checked)]'));

        $this->put(route('worker.receipts.update'), ['month' => '2026-11', 'has_receipts' => '0'])->assertSessionHasNoErrors();
        $this->put(route('worker.receipts.update'), ['month' => '2026-10', 'has_receipts' => '0'])->assertSessionHasErrors('month');

        $this->assertTrue($october->fresh()->has_receipts);
        $this->assertDatabaseHas('monthly_receipts', ['worker_id' => $worker->id, 'month' => '2026-11-01', 'has_receipts' => false]);
        $this->assertDatabaseCount('monthly_receipts', 2);
    }

    public function test_current_month_uses_israel_time_when_utc_is_still_in_the_previous_month(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 22:30:00', 'UTC')->setTimezone('Asia/Jerusalem'));
        $worker = User::factory()->create();

        $this->actingAs($worker)->put(route('worker.receipts.update'), ['month' => '2026-11', 'has_receipts' => '1'])
            ->assertSessionHasNoErrors();
        $this->put(route('worker.receipts.update'), ['month' => '2026-10', 'has_receipts' => '1'])
            ->assertSessionHasErrors('month');

        $this->assertDatabaseHas('monthly_receipts', ['worker_id' => $worker->id, 'month' => '2026-11-01']);
        $this->assertDatabaseCount('monthly_receipts', 1);
    }

    public function test_a_form_that_waits_across_the_month_boundary_is_rejected_after_the_user_lock(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 23:59:59', 'Asia/Jerusalem'));
        $worker = User::factory()->create();
        $advancedClock = false;

        DB::listen(function (QueryExecuted $query) use (&$advancedClock): void {
            $sql = strtolower($query->sql);

            if ($advancedClock || ! str_contains($sql, 'users') || ! str_contains($sql, 'for update')) {
                return;
            }

            // The request's October form becomes historical while waiting for
            // the worker lock; validating only before that wait would be unsafe.
            $advancedClock = true;
            $this->travelTo(CarbonImmutable::parse('2026-11-01 00:00:00', 'Asia/Jerusalem'));
        });

        $this->actingAs($worker)->put(route('worker.receipts.update'), ['month' => '2026-10', 'has_receipts' => '1'])
            ->assertSessionHasErrors('month');

        $this->assertTrue($advancedClock);
        $this->assertDatabaseCount('monthly_receipts', 0);
    }

    public function test_manager_report_counts_yes_no_and_missing_rows_for_all_workers_including_inactive_accounts(): void
    {
        $manager = User::factory()->manager()->create();
        $activeYes = User::factory()->create(['first_name' => 'Active', 'last_name' => 'Yes']);
        $inactiveYes = User::factory()->inactive()->create(['first_name' => 'Inactive', 'last_name' => 'Yes']);
        $explicitNo = User::factory()->create(['first_name' => 'Explicit', 'last_name' => 'No']);
        $missing = User::factory()->create(['first_name' => 'Missing', 'last_name' => 'No']);
        $this->createReceipt($activeYes, '2026-09', true);
        $this->createReceipt($inactiveYes, '2026-09', true);
        $this->createReceipt($explicitNo, '2026-09', false);
        $this->createReceipt($missing, '2026-10', true);

        $response = $this->actingAs($manager)->get(route('admin.users.index', ['receipts_month' => '2026-09']));

        $response->assertOk()->assertViewHas('receiptReport', function (array $report) use ($inactiveYes): bool {
            $this->assertSame('2026-09', $report['month']);
            $this->assertSame(2, $report['yes_count']);
            $this->assertSame(2, $report['no_count']);
            $this->assertSame(4, $report['total_count']);
            $rows = array_column($report['workers'], null, 'name');
            $this->assertFalse($rows[$inactiveYes->fullName()]['is_active']);
            $this->assertTrue($rows[$inactiveYes->fullName()]['has_receipts']);

            return true;
        });
        $document = $this->document($response->getContent());
        $this->assertCount(2, $document->query('//*[@id="receipts"]//tr[@data-receipt-state="yes"]'));
        $this->assertCount(2, $document->query('//*[@id="receipts"]//tr[@data-receipt-state="no"]'));
        $this->assertStringContainsString($inactiveYes->fullName(), $document->query('//*[@id="receipts"]')->item(0)->textContent);

        $current = app(MonthlyReceiptService::class)->report('2026-10');
        $this->assertSame(1, $current['yes_count']);
        $this->assertSame(3, $current['no_count']);
    }

    public function test_deactivation_keeps_historical_receipt_states_available_to_managers(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $receipt = app(MonthlyReceiptService::class)->set($worker, '2026-10', true);

        app(AccountManagementService::class)->deactivate($manager, $worker);

        $this->assertTrue($receipt->fresh()->has_receipts);
        $report = app(MonthlyReceiptService::class)->report('2026-10');
        $this->assertSame(1, $report['yes_count']);
        $this->assertFalse($report['workers'][0]['is_active']);
    }

    public function test_invalid_manager_month_filter_is_rejected_and_workers_cannot_read_the_report(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();

        $this->actingAs($manager)->get(route('admin.users.index', ['receipts_month' => '2026-13']))
            ->assertSessionHasErrors('receipts_month');
        $this->flushSession();
        $this->actingAs($worker)->get(route('admin.users.index', ['receipts_month' => '2026-10']))
            ->assertForbidden();
    }

    public function test_database_prevents_a_second_receipt_record_for_the_same_worker_and_month(): void
    {
        $worker = User::factory()->create();
        $this->createReceipt($worker, '2026-10', true);

        $this->expectException(QueryException::class);

        $this->createReceipt($worker, '2026-10', false);
    }

    #[DataProvider('invalidDatabaseStates')]
    public function test_database_rejects_noncanonical_months_and_non_boolean_receipt_states(string $month, int $state): void
    {
        $worker = User::factory()->create();

        $this->expectException(QueryException::class);

        // Bypass Eloquent's boolean cast to test the actual database invariant.
        DB::table('monthly_receipts')->insert([
            'worker_id' => $worker->id,
            'month' => $month,
            'has_receipts' => $state,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function invalidDatabaseStates(): array
    {
        return [
            'noncanonical month date' => ['2026-10-15', 1],
            'third receipt state' => ['2026-10-01', 2],
        ];
    }

    private function createReceipt(User $worker, string $month, bool $hasReceipts): MonthlyReceipt
    {
        return MonthlyReceipt::create([
            'worker_id' => $worker->id,
            'month' => $month.'-01',
            'has_receipts' => $hasReceipts,
        ]);
    }

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previousErrorMode = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorMode);

        return new DOMXPath($document);
    }
}
