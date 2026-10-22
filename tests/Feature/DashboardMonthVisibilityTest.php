<?php

namespace Tests\Feature;

use App\Models\BlockedDate;
use App\Models\CalendarEvent;
use App\Models\Desk;
use App\Models\MonthlyReceipt;
use App\Models\User;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardMonthVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Jerusalem'));
    }

    public function test_manager_lists_include_the_whole_current_month_and_keep_other_months_stored_but_hidden(): void
    {
        $manager = User::factory()->manager()->create();
        $visibleDates = ['2026-10-01', '2026-10-19', '2026-10-20', '2026-10-31'];
        $hiddenDates = ['2025-10-20', '2026-09-30', '2026-11-01', '2027-10-20'];

        foreach (array_merge($visibleDates, $hiddenDates) as $date) {
            $this->createEvent($manager, $date, $date === '2026-10-31' ? 'holiday' : 'event');
            $this->createBlockedDate($manager, $date);
        }

        $response = $this->actingAs($manager)->get(route('admin.users.index'))->assertOk();

        $this->assertSame($visibleDates, $this->dates($response->viewData('calendarEvents')));
        $this->assertSame($visibleDates, $this->dates($response->viewData('blockedDates')));
        $this->assertSame(4, $response->viewData('calendarEvents')->total());
        $this->assertSame(4, $response->viewData('blockedDates')->total());

        foreach ($visibleDates as $date) {
            $response->assertSee('Event '.$date)->assertSee('Block '.$date);
        }

        foreach ($hiddenDates as $date) {
            $response->assertDontSee('Event '.$date)->assertDontSee('Block '.$date);
            $this->assertDatabaseHas('calendar_events', ['date' => $date]);
            $this->assertDatabaseHas('blocked_dates', ['date' => $date]);
        }

        $this->assertDatabaseCount('calendar_events', 8);
        $this->assertDatabaseCount('blocked_dates', 8);
    }

    public function test_empty_current_month_lists_do_not_show_records_from_other_months(): void
    {
        $manager = User::factory()->manager()->create();

        foreach (['2026-09-30', '2026-11-01'] as $date) {
            $this->createEvent($manager, $date);
            $this->createBlockedDate($manager, $date);
        }

        $response = $this->actingAs($manager)->get(route('admin.users.index'))->assertOk();

        $response->assertSee('אין אירועים או חגים בחודש הנוכחי.')
            ->assertSee('אין תאריכים חסומים בחודש הנוכחי.');
        $this->assertSame(0, $response->viewData('calendarEvents')->total());
        $this->assertSame(0, $response->viewData('blockedDates')->total());
        $this->assertCount(0, $this->document($response->getContent())->query('//*[@id="events" or @id="blocks"]//nav[contains(@class,"pagination")]'));
        $this->assertDatabaseCount('calendar_events', 2);
        $this->assertDatabaseCount('blocked_dates', 2);
    }

    public function test_each_monthly_list_paginates_independently_and_counts_only_current_month_records(): void
    {
        $manager = User::factory()->manager()->create();
        $currentDates = [];

        for ($day = 1; $day <= 11; $day++) {
            $date = sprintf('2026-10-%02d', $day);
            $currentDates[] = $date;
            $this->createEvent($manager, $date);
            $this->createBlockedDate($manager, $date);
        }

        foreach (['2026-09-30', '2026-11-01'] as $date) {
            $this->createEvent($manager, $date);
            $this->createBlockedDate($manager, $date);
        }

        $this->actingAs($manager);
        $firstPage = $this->get(route('admin.users.index', ['receipts_month' => '2026-09']))->assertOk();

        foreach (['calendarEvents', 'blockedDates'] as $list) {
            $paginator = $firstPage->viewData($list);
            $this->assertSame(11, $paginator->total());
            $this->assertSame(10, $paginator->perPage());
            $this->assertSame(2, $paginator->lastPage());
            $this->assertSame(array_slice($currentDates, 0, 10), $this->dates($paginator));
        }

        $links = $this->document($firstPage->getContent());

        foreach (['events', 'blocks'] as $section) {
            $nextLink = $links->query('//*[@id="'.$section.'"]//nav/a')->item(0);
            $this->assertNotNull($nextLink);
            parse_str(parse_url($nextLink->getAttribute('href'), PHP_URL_QUERY), $query);
            $this->assertSame('2', $query[$section.'_page']);
            $this->assertSame('2026-09', $query['receipts_month']);
            $this->assertSame($section, parse_url($nextLink->getAttribute('href'), PHP_URL_FRAGMENT));
        }

        $eventsPage = $this->get(route('admin.users.index', ['events_page' => 2]))->assertOk();
        $this->assertSame(['2026-10-11'], $this->dates($eventsPage->viewData('calendarEvents')));
        $this->assertSame(array_slice($currentDates, 0, 10), $this->dates($eventsPage->viewData('blockedDates')));

        $blocksPage = $this->get(route('admin.users.index', ['blocks_page' => 2]))->assertOk();
        $this->assertSame(['2026-10-11'], $this->dates($blocksPage->viewData('blockedDates')));
        $this->assertSame(array_slice($currentDates, 0, 10), $this->dates($blocksPage->viewData('calendarEvents')));
    }

    public function test_receipt_report_month_and_unrecognized_month_filters_do_not_change_the_current_month_lists(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        MonthlyReceipt::create(['worker_id' => $worker->id, 'month' => '2026-09-01', 'has_receipts' => true]);

        foreach (['2026-09-30', '2026-10-01', '2026-11-01'] as $date) {
            $this->createEvent($manager, $date);
            $this->createBlockedDate($manager, $date);
        }

        $response = $this->actingAs($manager)->get(route('admin.users.index', [
            'receipts_month' => '2026-09',
            'month' => '2026-09',
            'events_month' => '2026-09',
            'blocks_month' => '2026-11',
        ]))->assertOk();

        $this->assertSame(['2026-10-01'], $this->dates($response->viewData('calendarEvents')));
        $this->assertSame(['2026-10-01'], $this->dates($response->viewData('blockedDates')));
        $this->assertSame('2026-09', $response->viewData('receiptReport')['month']);
        $this->assertSame(1, $response->viewData('receiptReport')['yes_count']);
        $response->assertDontSee('Event 2026-09-30')->assertDontSee('Block 2026-11-01');
    }

    #[DataProvider('monthRollovers')]
    public function test_lists_switch_months_at_israel_midnight_while_preserving_previous_records(string $utcAfterMidnight): void
    {
        $afterMidnight = CarbonImmutable::parse($utcAfterMidnight, 'UTC')->setTimezone('Asia/Jerusalem');
        $beforeMidnight = $afterMidnight->startOfMonth()->subSecond();
        $previousDate = $beforeMidnight->toDateString();
        $newDate = $afterMidnight->toDateString();
        $manager = User::factory()->manager()->create();

        foreach ([$previousDate, $newDate] as $date) {
            $this->createEvent($manager, $date);
            $this->createBlockedDate($manager, $date);
        }

        // At the first request UTC and Israel still share the previous month.
        $this->travelTo($beforeMidnight->utc());
        $before = $this->actingAs($manager)->get(route('admin.users.index'))->assertOk();
        $this->assertSame([$previousDate], $this->dates($before->viewData('calendarEvents')));
        $this->assertSame([$previousDate], $this->dates($before->viewData('blockedDates')));

        // UTC has not changed months yet, but Israel's dashboard must switch.
        $this->travelTo($afterMidnight->utc());
        $after = $this->get(route('admin.users.index'))->assertOk();
        $this->assertSame([$newDate], $this->dates($after->viewData('calendarEvents')));
        $this->assertSame([$newDate], $this->dates($after->viewData('blockedDates')));
        $after->assertDontSee('Event '.$previousDate)->assertDontSee('Block '.$previousDate);
        $this->assertDatabaseHas('calendar_events', ['date' => $previousDate]);
        $this->assertDatabaseHas('blocked_dates', ['date' => $previousDate]);
    }

    public static function monthRollovers(): array
    {
        return [
            'ordinary month boundary' => ['2026-10-31 22:30:00'],
            'year boundary' => ['2026-12-31 22:30:00'],
            'leap February boundary' => ['2028-02-29 22:30:00'],
        ];
    }

    #[DataProvider('futureEventKinds')]
    public function test_manager_can_create_a_future_month_event_or_holiday_without_showing_it_in_this_months_list(string $kind): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->post(route('admin.events.store'), [
            'title' => 'Future month announcement',
            'event_date' => '2026-11-01',
            'kind' => $kind,
            'description' => null,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $dashboard = $this->get(route('admin.users.index'))->assertOk();

        $dashboard->assertDontSee('Future month announcement');
        $this->assertSame(0, $dashboard->viewData('calendarEvents')->total());
        $this->assertDatabaseHas('calendar_events', [
            'title' => 'Future month announcement', 'date' => '2026-11-01', 'kind' => $kind,
        ]);
    }

    public static function futureEventKinds(): array
    {
        return [['event'], ['holiday']];
    }

    public function test_a_hidden_next_month_block_still_rejects_worker_shifts_and_desk_reservations(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();

        $this->actingAs($manager)->post(route('admin.blocks.store'), [
            'shift_date' => '2026-11-01',
            'reason' => 'Next month closure',
            'confirm_block' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $dashboard = $this->get(route('admin.users.index'))->assertOk();
        $dashboard->assertDontSee('Next month closure');
        $this->assertSame(0, $dashboard->viewData('blockedDates')->total());

        $this->flushSession();
        $this->actingAs($worker)->post(route('worker.shifts.store'), [
            'shift_date' => '2026-11-01',
            'period' => 'full',
        ])->assertSessionHasErrors('shift_date');
        $this->post(route('worker.desks.store'), [
            'desk_id' => $desk->id,
            'reservation_date' => '2026-11-01',
            'start_time' => '09:00',
            'end_time' => '10:00',
        ])->assertSessionHasErrors('reservation_date');

        $this->assertDatabaseHas('blocked_dates', ['date' => '2026-11-01', 'reason' => 'Next month closure']);
        $this->assertDatabaseCount('shifts', 0);
        $this->assertDatabaseCount('desk_reservations', 0);
    }

    private function createEvent(User $manager, string $date, string $kind = 'event'): CalendarEvent
    {
        return CalendarEvent::create([
            'title' => 'Event '.$date,
            'date' => $date,
            'kind' => $kind,
            'description' => null,
            'created_by' => $manager->id,
            'updated_by' => $manager->id,
        ]);
    }

    /** Historical fixtures bypass today's prohibition on creating past blocks. */
    private function createBlockedDate(User $manager, string $date): void
    {
        DB::table('calendar_dates')->insertOrIgnore(['date' => $date]);
        BlockedDate::create(['date' => $date, 'reason' => 'Block '.$date, 'blocked_by' => $manager->id]);
    }

    private function dates(iterable $records): array
    {
        $dates = [];

        foreach ($records as $record) {
            $dates[] = $record->date->toDateString();
        }

        return $dates;
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
