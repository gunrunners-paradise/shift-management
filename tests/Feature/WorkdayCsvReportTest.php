<?php

namespace Tests\Feature;

use App\Models\MonthlyReceipt;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\AccountManagementService;
use App\Services\WorkdayCsvReportService;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesShiftRecords;
use Tests\TestCase;

class WorkdayCsvReportTest extends TestCase
{
    use CreatesShiftRecords, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00', 'Asia/Jerusalem'));
    }

    public function test_manager_explicitly_downloads_a_hebrew_csv_with_bom_and_four_ordered_columns(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create(['first_name' => 'דנה', 'last_name' => 'כהן']);
        $this->createShiftRecord($worker, '2026-10-20');

        $response = $this->download($manager, '2026-10-20', '2026-10-20');

        $response->assertOk()->assertDownload();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('charset=utf-8', strtolower($response->headers->get('Content-Type')));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertSame(1, substr_count($csv, "\xEF\xBB\xBF"));
        $this->assertTrue(mb_check_encoding($csv, 'UTF-8'));
        $this->assertSame([
            ['שם העובד', 'מספר ימי עבודה', 'תאריכי עבודה', 'קבלות'],
            ['דנה כהן', '1', '20/10/2026', 'אין'],
        ], $this->csvRows($csv));
        $this->assertMatchesRegularExpression('/"20\/10\/2026","אין"\r?\n/', $csv);
    }

    public function test_all_five_shift_categories_each_count_as_one_workday_in_chronological_order(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $periodsByDate = [
            '2026-10-22' => 'full',
            '2026-10-20' => '11_15',
            '2026-10-18' => '09_13',
            '2026-10-21' => '12_16',
            '2026-10-19' => '10_14',
        ];

        foreach ($periodsByDate as $date => $period) {
            $this->createShiftRecord($worker, $date, $period);
        }

        $csv = $this->download($manager)->streamedContent();

        $this->assertSame([
            ['שם העובד', 'מספר ימי עבודה', 'תאריכי עבודה', 'קבלות'],
            [$worker->fullName(), '5', '18/10/2026, 19/10/2026, 20/10/2026, 21/10/2026, 22/10/2026', 'אין'],
        ], $this->csvRows($csv));
        $this->assertStringContainsString('"18/10/2026, 19/10/2026, 20/10/2026, 21/10/2026, 22/10/2026"', $csv);
    }

    public function test_four_hour_and_full_shifts_on_different_dates_count_two_days_rather_than_hours(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $this->createShiftRecord($worker, '2026-10-20', '09_13');
        $this->createShiftRecord($worker, '2026-10-21', 'full');

        $rows = $this->csvRows($this->download($manager)->streamedContent());

        $this->assertSame([$worker->fullName(), '2', '20/10/2026, 21/10/2026', 'אין'], $rows[1]);
    }

    public function test_date_range_is_inclusive_and_excludes_records_immediately_outside_it(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();

        foreach (['2026-10-18', '2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22'] as $date) {
            $this->createShiftRecord($worker, $date);
        }

        $rows = $this->csvRows($this->download($manager, '2026-10-19', '2026-10-21')->streamedContent());

        $this->assertSame([$worker->fullName(), '3', '19/10/2026, 20/10/2026, 21/10/2026', 'אין'], $rows[1]);
    }

    public function test_canceled_records_do_not_count_and_replacement_history_does_not_count_a_date_twice(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $cancellation = ['cancelled_at' => now(), 'cancelled_by' => $worker->id, 'cancellation_reason' => 'worker_cancelled'];
        $this->createShiftRecord($worker, '2026-10-19', '09_13', $cancellation);
        $this->createShiftRecord($worker, '2026-10-20', '10_14', $cancellation);
        $this->createShiftRecord($worker, '2026-10-20', '11_15', $cancellation);
        $this->createShiftRecord($worker, '2026-10-20', 'full');

        $rows = $this->csvRows($this->download($manager)->streamedContent());

        $this->assertSame([$worker->fullName(), '1', '20/10/2026', 'אין'], $rows[1]);
        $this->assertDatabaseCount('shifts', 4);
    }

    public function test_deactivated_workers_keep_their_historical_scheduled_days_in_the_report(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $this->createReceiptRecord($worker, '2026-10', true);
        $pastShift = $this->createShiftRecord($worker, '2026-10-20');
        $this->createShiftRecord($worker, '2026-10-25');
        app(AccountManagementService::class)->deactivate($manager, $worker);

        $rows = $this->csvRows($this->download($manager)->streamedContent());

        $this->assertSame([$worker->fullName(), '1', '20/10/2026', 'יש'], $rows[1]);
        $this->assertFalse($worker->fresh()->is_active);
        $this->assertNull($pastShift->fresh()->cancelled_at);
    }

    public function test_workers_with_zero_days_are_included_even_if_inactive_or_all_their_shifts_are_canceled(): void
    {
        $manager = User::factory()->manager()->create();
        $missing = User::factory()->create(['first_name' => 'ללא', 'last_name' => 'משמרות']);
        $inactive = User::factory()->inactive()->create(['first_name' => 'חשבון', 'last_name' => 'מושבת']);
        $canceled = User::factory()->create(['first_name' => 'משמרת', 'last_name' => 'בוטלה']);
        $outside = User::factory()->create(['first_name' => 'מחוץ', 'last_name' => 'לטווח']);
        $this->createShiftRecord($canceled, '2026-10-20', 'full', [
            'cancelled_at' => now(), 'cancelled_by' => $manager->id, 'cancellation_reason' => 'date_blocked',
        ]);
        $this->createShiftRecord($outside, '2026-09-30');

        $csv = $this->download($manager)->streamedContent();
        $rows = $this->csvRows($csv);

        $this->assertCount(5, $rows);
        $this->assertMatchesRegularExpression('/,"","אין"\r?\n/', $csv);
        $rowsByName = array_column(array_slice($rows, 1), null, 0);

        foreach ([$missing, $inactive, $canceled, $outside] as $worker) {
            $this->assertSame([$worker->fullName(), '0', '', 'אין'], $rowsByName[$worker->fullName()]);
        }
    }

    public function test_managers_never_appear_as_worker_rows_even_if_legacy_shift_records_exist(): void
    {
        $manager = User::factory()->manager()->create();
        $inactiveManager = User::factory()->manager()->inactive()->create();
        $worker = User::factory()->create();
        // A manager cannot create personal shifts through the application.
        // Direct fixtures ensure reporting also filters unexpected legacy rows.
        $this->createShiftRecord($manager, '2026-10-20');
        $this->createShiftRecord($inactiveManager, '2026-10-20');
        $this->createReceiptRecord($manager, '2026-10', true);
        $this->createReceiptRecord($inactiveManager, '2026-10', true);

        $rows = $this->csvRows($this->download($manager)->streamedContent());

        $this->assertSame([
            ['שם העובד', 'מספר ימי עבודה', 'תאריכי עבודה', 'קבלות'],
            [$worker->fullName(), '0', '', 'אין'],
        ], $rows);
    }

    public function test_permanently_deleted_accounts_and_history_are_absent_from_later_exports(): void
    {
        $manager = User::factory()->manager()->create();
        $deletedWorker = User::factory()->inactive()->create(['first_name' => 'נמחק', 'last_name' => 'בדיקה']);
        $retainedWorker = User::factory()->create();
        $this->createShiftRecord($deletedWorker, '2026-10-20');
        $this->createReceiptRecord($deletedWorker, '2026-10', true);
        app(AccountDeletionService::class)->delete($manager, $deletedWorker);

        $rows = $this->csvRows($this->download($manager)->streamedContent());

        $this->assertSame([
            ['שם העובד', 'מספר ימי עבודה', 'תאריכי עבודה', 'קבלות'],
            [$retainedWorker->fullName(), '0', '', 'אין'],
        ], $rows);
        $this->assertDatabaseMissing('shifts', ['worker_id' => $deletedWorker->id]);
    }

    public function test_workers_with_identical_names_remain_separate_accounts_in_the_export(): void
    {
        $manager = User::factory()->manager()->create();
        $firstWorker = User::factory()->create(['first_name' => 'דנה', 'last_name' => 'כהן']);
        $secondWorker = User::factory()->create(['first_name' => 'דנה', 'last_name' => 'כהן']);
        $this->createShiftRecord($firstWorker, '2026-10-20');
        $this->createShiftRecord($secondWorker, '2026-10-21');
        $this->createReceiptRecord($firstWorker, '2026-10', true);
        $this->createReceiptRecord($secondWorker, '2026-10', false);

        $rows = $this->csvRows($this->download($manager)->streamedContent());

        $this->assertCount(3, $rows);
        $this->assertSame(['דנה כהן', '1', '20/10/2026', 'יש'], $rows[1]);
        $this->assertSame(['דנה כהן', '1', '21/10/2026', 'אין'], $rows[2]);
    }

    public function test_receipts_column_uses_yes_no_for_checked_unchecked_and_missing_month_records(): void
    {
        $manager = User::factory()->manager()->create();
        $checked = User::factory()->create(['first_name' => 'סימון', 'last_name' => 'קיים']);
        $unchecked = User::factory()->create(['first_name' => 'סימון', 'last_name' => 'ריק']);
        $missing = User::factory()->create(['first_name' => 'ללא', 'last_name' => 'רשומה']);
        $this->createReceiptRecord($checked, '2026-10', true);
        $this->createReceiptRecord($unchecked, '2026-10', false);
        // Another month's checkmark must not count as the selected month.
        $this->createReceiptRecord($missing, '2026-09', true);

        $rows = $this->csvRows($this->download($manager)->streamedContent());
        $rowsByName = array_column(array_slice($rows, 1), null, 0);

        $this->assertCount(4, $rows);
        $this->assertSame([$checked->fullName(), '0', '', 'יש'], $rowsByName[$checked->fullName()]);
        $this->assertSame([$unchecked->fullName(), '0', '', 'אין'], $rowsByName[$unchecked->fullName()]);
        $this->assertSame([$missing->fullName(), '0', '', 'אין'], $rowsByName[$missing->fullName()]);
        $this->assertDatabaseCount('monthly_receipts', 3);
    }

    public function test_inactive_zero_day_workers_keep_their_selected_month_receipts_state(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->inactive()->create();
        $this->createReceiptRecord($worker, '2026-09', true);

        $rows = $this->csvRows($this->download($manager, '2026-10-18', '2026-10-22', '2026-09')->streamedContent());

        $this->assertSame([$worker->fullName(), '0', '', 'יש'], $rows[1]);
        $this->assertFalse($worker->fresh()->is_active);
    }

    public function test_receipt_month_is_independent_of_a_multimonth_work_range_without_multiplying_shift_dates(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $this->createShiftRecord($worker, '2026-08-31');
        $this->createShiftRecord($worker, '2026-09-30');
        $this->createShiftRecord($worker, '2026-10-20');
        $this->createReceiptRecord($worker, '2026-08', true);
        $this->createReceiptRecord($worker, '2026-09', false);
        $this->createReceiptRecord($worker, '2026-10', true);

        $september = $this->csvRows($this->download($manager, '2026-08-01', '2026-10-22', '2026-09')->streamedContent());
        $october = $this->csvRows($this->download($manager, '2026-08-01', '2026-10-22', '2026-10')->streamedContent());

        $this->assertCount(2, $september);
        $this->assertCount(2, $october);
        $this->assertSame([$worker->fullName(), '3', '31/08/2026, 30/09/2026, 20/10/2026', 'אין'], $september[1]);
        $this->assertSame([$worker->fullName(), '3', '31/08/2026, 30/09/2026, 20/10/2026', 'יש'], $october[1]);
    }

    public function test_csv_receipt_month_is_independent_of_the_dashboard_receipt_report_query(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $this->createReceiptRecord($worker, '2026-09', true);
        $this->createReceiptRecord($worker, '2026-10', false);

        $response = $this->actingAs($manager)->post(route('admin.reports.workdays', ['receipts_month' => '2026-10']), [
            'start_date' => '2026-10-18', 'end_date' => '2026-10-22', 'receipt_month' => '2026-09',
        ]);

        $response->assertOk()->assertDownload('scheduled-workdays-2026-10-18-to-2026-10-22-receipts-2026-09.csv');
        $this->assertSame([$worker->fullName(), '0', '', 'יש'], $this->csvRows($response->streamedContent())[1]);
    }

    public function test_managers_may_select_a_future_receipt_month_without_exporting_future_workdays(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $this->createShiftRecord($worker, '2026-10-20');
        $this->createReceiptRecord($worker, '2027-01', true);

        $response = $this->download($manager, '2026-10-18', '2026-10-22', '2027-01');

        $response->assertOk()->assertDownload();
        $this->assertSame([$worker->fullName(), '1', '20/10/2026', 'יש'], $this->csvRows($response->streamedContent())[1]);
    }

    public function test_a_report_without_any_workers_contains_only_the_hebrew_header(): void
    {
        $manager = User::factory()->manager()->create();
        User::factory()->manager()->inactive()->create();

        $rows = $this->csvRows($this->download($manager)->streamedContent());

        $this->assertSame([['שם העובד', 'מספר ימי עבודה', 'תאריכי עבודה', 'קבלות']], $rows);
    }

    public function test_long_historical_date_lists_are_not_truncated_by_a_database_concatenation_limit(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $date = CarbonImmutable::parse('2026-01-01', 'Asia/Jerusalem');
        $expectedDates = [];

        // This protects long histories when another deployment uses a lower
        // GROUP_CONCAT limit, such as 1,024 bytes. Every date must still export.
        while (count($expectedDates) < 100) {
            if (! $date->isFriday() && ! $date->isSaturday()) {
                $this->createShiftRecord($worker, $date->toDateString());
                $expectedDates[] = $date->format('d/m/Y');
            }

            $date = $date->addDay();
        }

        $rows = $this->csvRows($this->download($manager, '2026-01-01', '2026-10-22')->streamedContent());
        $expectedDateCell = implode(', ', $expectedDates);

        $this->assertGreaterThan(1024, strlen($expectedDateCell));
        $this->assertSame([$worker->fullName(), '100', $expectedDateCell, 'אין'], $rows[1]);
    }

    public function test_names_with_hebrew_commas_quotes_newlines_and_backslashes_round_trip_as_one_csv_cell(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create(['first_name' => "דנה, \"דני\"\nשורה\\", 'last_name' => 'כהן']);
        $this->createShiftRecord($worker, '2026-10-20');

        $csv = $this->download($manager)->streamedContent();
        $rows = $this->csvRows($csv);

        $this->assertCount(2, $rows);
        $this->assertSame([$worker->fullName(), '1', '20/10/2026', 'אין'], $rows[1]);
        $this->assertStringContainsString('""דני""', $csv);
    }

    #[DataProvider('dangerousNamePrefixes')]
    public function test_potential_spreadsheet_formulas_in_names_are_forced_to_text(string $firstName): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create(['first_name' => $firstName, 'last_name' => 'עובד']);

        $rows = $this->csvRows($this->download($manager)->streamedContent());

        $this->assertSame("'".$worker->fullName(), $rows[1][0]);
        $this->assertSame(['0', '', 'אין'], array_slice($rows[1], 1));
    }

    public static function dangerousNamePrefixes(): array
    {
        return [
            'equals formula' => ['=1+1'],
            'plus formula' => ['+1+1'],
            'minus formula' => ['-1+1'],
            'at formula' => ['@SUM(1,2)'],
            'leading tab' => ["\tדנה"],
            'leading carriage return' => ["\rדנה"],
            'leading newline' => ["\nדנה"],
            'spaces before formula' => ['  =1+1'],
            'tab before formula' => ["\t=1+1"],
            'nonbreaking space before formula' => ["\u{00A0}=1+1"],
            'byte-order mark before formula' => ["\u{FEFF}=1+1"],
            'fullwidth equals formula' => ['＝1+1'],
            'fullwidth plus formula' => ['＋1+1'],
            'fullwidth minus formula' => ['－1+1'],
            'fullwidth at formula' => ['＠SUM(1,2)'],
        ];
    }

    public function test_dashboard_shows_a_native_post_form_and_quick_action_without_generating_a_report(): void
    {
        $manager = User::factory()->manager()->create();
        $this->mock(WorkdayCsvReportService::class)->shouldNotReceive('prepare');

        $response = $this->actingAs($manager)->get(route('admin.users.index'));

        $response->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $document = $this->document($response->getContent());
        $this->assertCount(1, $document->query('//*[@id="reports"]'));
        $this->assertCount(1, $document->query('//a[@href="#reports"]'));
        $forms = $document->query('//*[@id="reports"]//form[@action="'.route('admin.reports.workdays').'"]');
        $this->assertCount(1, $forms);
        $form = $forms->item(0);
        $this->assertSame('post', strtolower($form->getAttribute('method')));
        $this->assertCount(1, $document->query('.//input[@type="hidden" and @name="_token"]', $form));

        foreach (['start_date', 'end_date'] as $name) {
            $this->assertCount(1, $document->query('.//input[@type="date" and @name="'.$name.'" and @required and @max="2026-10-22"]', $form));
        }

        $receiptInputs = $document->query('.//input[@type="month" and @name="receipt_month" and @required and @value="2026-10"]', $form);
        $this->assertCount(1, $receiptInputs);
        $receiptInput = $receiptInputs->item(0);
        $this->assertFalse($receiptInput->hasAttribute('min'));
        $this->assertFalse($receiptInput->hasAttribute('max'));
        $labels = $document->query('.//label[@for="'.$receiptInput->getAttribute('id').'"]', $form);
        $this->assertCount(1, $labels);
        $this->assertStringContainsString('חודש קבלות', $labels->item(0)->textContent);
        $this->assertCount(1, $document->query('.//button[@type="submit"]', $form));
        $this->assertCount(0, $document->query('//*[@id="reports"]//table'));
    }

    public function test_receipt_month_defaults_to_actual_israel_month_independently_of_dashboard_month_selection(): void
    {
        // Israel is already in November while UTC still belongs to October.
        $this->travelTo(CarbonImmutable::parse('2026-10-31 22:30:00', 'UTC'));
        $manager = User::factory()->manager()->create();
        $this->mock(WorkdayCsvReportService::class)->shouldNotReceive('prepare');

        $response = $this->actingAs($manager)->get(route('admin.users.index', ['receipts_month' => '2026-09']));

        $response->assertOk();
        $document = $this->document($response->getContent());
        $this->assertCount(1, $document->query('//*[@id="reports"]//input[@name="receipt_month" and @value="2026-11"]'));
        $this->assertCount(1, $document->query('//*[@id="receipts"]//input[@name="receipts_month" and @value="2026-09"]'));
    }

    public function test_valid_receipt_month_is_redisplayed_when_the_workday_range_is_rejected(): void
    {
        $manager = User::factory()->manager()->create();
        $response = $this->actingAs($manager)->from(route('admin.users.index'))
            ->post(route('admin.reports.workdays'), [
                'start_date' => '2026-10-18', 'end_date' => '2026-10-23', 'receipt_month' => '2026-09',
            ]);

        $response->assertSessionHasErrors('end_date');
        $this->withCookie(config('session.cookie'), session()->getId());
        $page = $this->followRedirects($response)->assertOk();
        $document = $this->document($page->getContent());

        $this->assertCount(1, $document->query('//*[@id="reports"]//input[@name="receipt_month" and @value="2026-09"]'));
    }

    public function test_opening_the_download_url_with_get_does_not_generate_a_report(): void
    {
        $manager = User::factory()->manager()->create();
        $this->mock(WorkdayCsvReportService::class)->shouldNotReceive('prepare');

        $this->actingAs($manager)->get(route('admin.reports.workdays', [
            'start_date' => '2026-10-18', 'end_date' => '2026-10-22', 'receipt_month' => '2026-10',
        ]))->assertStatus(405);
    }

    public function test_export_does_not_change_business_records_save_a_report_or_dispatch_delivery_jobs(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $this->createShiftRecord($worker, '2026-10-20');
        $this->createReceiptRecord($worker, '2026-10', true);
        $this->createReceiptRecord($worker, '2026-09', false);
        User::factory()->create();
        $tables = ['users', 'shifts', 'calendar_dates', 'blocked_dates', 'calendar_events', 'desks', 'desk_reservations', 'monthly_receipts'];
        $before = [];

        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }

        Storage::fake('local');
        Storage::fake('public');
        Queue::fake();
        Mail::fake();

        $this->csvRows($this->download($manager)->streamedContent());

        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->get()->toJson(), $table.' must remain unchanged.');
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
        Queue::assertNothingPushed();
        Mail::assertNothingOutgoing();
    }

    #[DataProvider('invalidDateRanges')]
    public function test_invalid_date_ranges_are_rejected_before_streaming(array $input, string $errorField): void
    {
        $manager = User::factory()->manager()->create();
        $this->mock(WorkdayCsvReportService::class)->shouldNotReceive('prepare');

        $this->actingAs($manager)->from(route('admin.users.index'))
            ->post(route('admin.reports.workdays'), array_merge(['receipt_month' => '2026-10'], $input))
            ->assertSessionHasErrors($errorField)
            ->assertRedirect();
    }

    public static function invalidDateRanges(): array
    {
        $valid = ['start_date' => '2026-10-18', 'end_date' => '2026-10-22'];

        return [
            'missing start' => [['end_date' => '2026-10-22'], 'start_date'],
            'missing end' => [['start_date' => '2026-10-18'], 'end_date'],
            'empty start' => [array_replace($valid, ['start_date' => '']), 'start_date'],
            'invalid calendar date' => [array_replace($valid, ['start_date' => '2026-02-30']), 'start_date'],
            'unrecognized date' => [array_replace($valid, ['start_date' => 'not-a-date']), 'start_date'],
            'ambiguous date format' => [array_replace($valid, ['start_date' => '18/10/2026']), 'start_date'],
            'noncanonical date format' => [array_replace($valid, ['start_date' => '2026-1-2']), 'start_date'],
            'timestamp instead of date' => [array_replace($valid, ['start_date' => '2026-10-18T00:00:00Z']), 'start_date'],
            'array start' => [array_replace($valid, ['start_date' => ['2026-10-18']]), 'start_date'],
            'array end' => [array_replace($valid, ['end_date' => ['2026-10-22']]), 'end_date'],
            'reversed range' => [['start_date' => '2026-10-21', 'end_date' => '2026-10-20'], 'end_date'],
            'future end' => [array_replace($valid, ['end_date' => '2026-10-23']), 'end_date'],
            'future start and end' => [['start_date' => '2026-10-25', 'end_date' => '2026-10-26'], 'start_date'],
        ];
    }

    public function test_array_date_input_redisplays_hebrew_feedback_without_a_server_error(): void
    {
        $manager = User::factory()->manager()->create();
        $response = $this->actingAs($manager)->from(route('admin.users.index'))
            ->post(route('admin.reports.workdays'), ['start_date' => ['invalid'], 'end_date' => '2026-10-22', 'receipt_month' => '2026-10']);

        $response->assertSessionHasErrors('start_date');
        $message = session('errors')->first('start_date');
        $this->assertMatchesRegularExpression('/[\x{0590}-\x{05FF}]/u', $message);
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->followRedirects($response)->assertOk()->assertSee($message);
    }

    #[DataProvider('invalidReceiptMonths')]
    public function test_invalid_receipt_months_are_rejected_with_hebrew_feedback_before_streaming(array $monthInput): void
    {
        $manager = User::factory()->manager()->create();
        $this->mock(WorkdayCsvReportService::class)->shouldNotReceive('prepare');

        $response = $this->actingAs($manager)->from(route('admin.users.index'))
            ->post(route('admin.reports.workdays'), array_merge([
                'start_date' => '2026-10-18', 'end_date' => '2026-10-22',
            ], $monthInput));

        $response->assertRedirect(route('admin.users.index'))->assertSessionHasErrors('receipt_month');
        $message = session('errors')->first('receipt_month');
        $this->assertStringContainsString('חודש הקבלות', $message);
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->followRedirects($response)->assertOk()->assertSee($message);
    }

    public static function invalidReceiptMonths(): array
    {
        return [
            'missing month' => [[]],
            'null month' => [['receipt_month' => null]],
            'empty month' => [['receipt_month' => '']],
            'array month' => [['receipt_month' => ['2026-10']]],
            'noncanonical month' => [['receipt_month' => '2026-1']],
            'day instead of month' => [['receipt_month' => '2026-10-01']],
            'unrecognized month' => [['receipt_month' => 'not-a-month']],
            'ambiguous month' => [['receipt_month' => '10/2026']],
            'zero month' => [['receipt_month' => '2026-00']],
            'month beyond December' => [['receipt_month' => '2026-13']],
            'timestamp suffix' => [['receipt_month' => '2026-10T00:00:00Z']],
        ];
    }

    public function test_today_is_based_on_israel_calendar_date_when_utc_is_still_the_previous_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-21 22:30:00', 'UTC'));
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $this->createShiftRecord($worker, '2026-10-22');

        $rows = $this->csvRows($this->download($manager, '2026-10-22', '2026-10-22')->streamedContent());

        $this->assertSame([$worker->fullName(), '1', '22/10/2026', 'אין'], $rows[1]);
        $this->post(route('admin.reports.workdays'), ['start_date' => '2026-10-22', 'end_date' => '2026-10-23', 'receipt_month' => '2026-10'])
            ->assertSessionHasErrors('end_date');
    }

    public function test_future_shifts_are_not_included_in_an_export_ending_today(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $this->createShiftRecord($worker, '2026-10-22', '09_13');
        $this->createShiftRecord($worker, '2026-10-25', 'full');

        $rows = $this->csvRows($this->download($manager)->streamedContent());

        $this->assertSame([$worker->fullName(), '1', '22/10/2026', 'אין'], $rows[1]);
        $this->assertDatabaseHas('shifts', ['worker_id' => $worker->id, 'shift_date' => '2026-10-25', 'cancelled_at' => null]);
    }

    public function test_guest_cannot_download_the_manager_report(): void
    {
        $this->mock(WorkdayCsvReportService::class)->shouldNotReceive('prepare');

        $this->post(route('admin.reports.workdays'), ['start_date' => '2026-10-18', 'end_date' => '2026-10-22', 'receipt_month' => '2026-10'])
            ->assertRedirect(route('login'));
    }

    public function test_worker_cannot_download_the_manager_report_through_a_guessed_url(): void
    {
        $worker = User::factory()->create();
        $this->mock(WorkdayCsvReportService::class)->shouldNotReceive('prepare');

        $this->download($worker)->assertForbidden();
    }

    public function test_inactive_manager_cannot_download_and_their_session_is_ended(): void
    {
        $manager = User::factory()->manager()->inactive()->create();
        $this->mock(WorkdayCsvReportService::class)->shouldNotReceive('prepare');

        $this->download($manager)->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[DataProvider('staleActorChanges')]
    public function test_streaming_rechecks_a_stale_managers_current_authority_before_writing_csv(array $changes): void
    {
        $manager = User::factory()->manager()->create();
        User::query()->whereKey($manager->id)->update($changes);
        $denied = false;
        ob_start();

        try {
            app(WorkdayCsvReportService::class)->prepare($manager, '2026-10-18', '2026-10-22', '2026-10');
        } catch (AuthorizationException) {
            $denied = true;
        } finally {
            $output = ob_get_clean();
        }

        $this->assertTrue($denied);
        $this->assertSame('', $output);
    }

    public static function staleActorChanges(): array
    {
        return [
            'now inactive' => [['is_active' => false]],
            'now worker' => [['role' => 'worker']],
            'reactivated with a newer session version' => [['session_version' => 1]],
        ];
    }

    /** Every request represents an explicit manager click, not a dashboard read. */
    private function download(User $actor, string $start = '2026-10-18', string $end = '2026-10-22', string $receiptMonth = '2026-10'): TestResponse
    {
        return $this->actingAs($actor)->post(route('admin.reports.workdays'), [
            'start_date' => $start, 'end_date' => $end, 'receipt_month' => $receiptMonth,
        ]);
    }

    /** Parse actual CSV records, including embedded newlines, with RFC-style escaping. */
    private function csvRows(string $csv): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $rows = [];

        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $this->assertCount(4, $row, 'Every record must have exactly four cells.');
            $rows[] = $row;
        }

        fclose($stream);

        return $rows;
    }

    /** Store the same first-of-month key used by the worker's receipt checkbox. */
    private function createReceiptRecord(User $worker, string $month, bool $hasReceipts): MonthlyReceipt
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
