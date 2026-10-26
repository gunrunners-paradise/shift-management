<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\MonthlyReceipt;
use App\Models\Shift;
use App\Models\User;
use App\Services\DateClosureService;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WeeklyCalendarEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00', 'Asia/Jerusalem'));
    }

    public function test_future_events_appear_under_their_matching_dates_only_within_the_viewed_workweek(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $this->createEvent($manager, '2026-10-31', 'שבת לפני השבוע');
        $this->createEvent($manager, '2026-11-01', 'אירוע ביום ראשון');
        $this->createEvent($manager, '2026-11-03', 'אירוע ביום שלישי');
        $this->createEvent($manager, '2026-11-05', 'אירוע ביום חמישי');
        $this->createEvent($manager, '2026-11-06', 'שישי אחרי השבוע');

        $response = $this->actingAs($worker)->get(route('worker.home', ['week' => '2026-11-04']));

        $response->assertOk();
        $document = $this->document($response->getContent());
        $this->assertSame(['אירוע ביום ראשון'], $this->titlesUnderDate($document, '2026-11-01'));
        $this->assertSame([], $this->titlesUnderDate($document, '2026-11-02'));
        $this->assertSame(['אירוע ביום שלישי'], $this->titlesUnderDate($document, '2026-11-03'));
        $this->assertSame(['אירוע ביום חמישי'], $this->titlesUnderDate($document, '2026-11-05'));
        $response->assertDontSee('שבת לפני השבוע')->assertDontSee('שישי אחרי השבוע');

        foreach ($document->query('//ul[contains(@class,"calendar-date-events")]') as $list) {
            $this->assertCount(1, $document->query('preceding-sibling::time', $list), 'Event labels must follow the date in its header cell.');
        }
    }

    public function test_multiple_events_and_a_manual_holiday_are_listed_without_collapsing_or_replacing_titles(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $this->createEvent($manager, '2026-11-02', 'אירוע ראשון');
        $this->createEvent($manager, '2026-11-02', 'חג ידני', 'holiday');
        $this->createEvent($manager, '2026-11-02', 'אירוע נוסף');

        $response = $this->actingAs($worker)->get(route('worker.home', ['week' => '2026-11-01']));

        $response->assertOk();
        $document = $this->document($response->getContent());
        $header = $this->dateHeader($document, '2026-11-02');
        $this->assertSame(['אירוע ראשון', 'חג ידני', 'אירוע נוסף'], $this->titlesUnderDate($document, '2026-11-02'));
        $kindLabels = [];

        foreach ($document->query('.//span[contains(@class,"calendar-date-event-kind")]', $header) as $label) {
            $kindLabels[] = trim($label->textContent);
        }

        $this->assertSame(['אירוע', 'חג', 'אירוע'], $kindLabels);
        $this->assertCount(0, $document->query('.//a | .//button', $header), 'Informational labels must not pretend to be booking actions.');
    }

    public function test_a_week_without_events_keeps_empty_date_headers_without_placeholder_lists(): void
    {
        $worker = User::factory()->create();

        $response = $this->actingAs($worker)->get(route('worker.home', ['week' => '2026-11-01']));

        $response->assertOk();
        $document = $this->document($response->getContent());
        $this->assertCount(5, $document->query('//tr[contains(@class,"calendar-dates")]/th[time]'));
        $this->assertCount(0, $document->query('//ul[contains(@class,"calendar-date-events")]'));
        $this->assertCount(5, $document->query('//table[contains(@class,"shift-calendar")]/tbody/tr'));
    }

    public function test_event_titles_are_escaped_in_date_headers_and_descriptions_do_not_expand_the_header(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $title = '<script>alert("date")</script> & אירוע';
        $description = 'תיאור מפורט שאינו שייך לכותרת התאריך';
        $this->createEvent($manager, '2026-11-02', $title, 'event', $description);

        $response = $this->actingAs($worker)->get(route('worker.home', ['week' => '2026-11-01']));

        $response->assertOk()->assertSee(e($title), false)->assertDontSee($title, false)->assertDontSee($description);
        $document = $this->document($response->getContent());
        $this->assertSame([$title], $this->titlesUnderDate($document, '2026-11-02'));
        $this->assertCount(0, $document->query('.//script | .//img', $this->dateHeader($document, '2026-11-02')));
    }

    public function test_browsing_future_events_preserves_actual_today_notices_birthdays_and_receipt_month(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create(['birth_date' => '2000-10-26']);
        $this->createEvent($manager, '2026-10-26', 'הודעה של היום');
        $this->createEvent($manager, '2026-11-03', 'אירוע עתידי בכותרת');
        MonthlyReceipt::create(['worker_id' => $worker->id, 'month' => '2026-10-01', 'has_receipts' => true]);

        $response = $this->actingAs($worker)->get(route('worker.home', ['week' => '2026-11-01']));

        $response->assertOk()->assertViewHas('receiptMonth', '2026-10')->assertViewHas('hasReceipts', true);
        $document = $this->document($response->getContent());
        $this->assertSame(['אירוע עתידי בכותרת'], $this->titlesUnderDate($document, '2026-11-03'));
        $announcements = $document->query('//*[@id="announcements"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $announcements);
        $this->assertStringContainsString('הודעה של היום', $announcements->textContent);
        $this->assertStringNotContainsString('אירוע עתידי בכותרת', $announcements->textContent);
        $this->assertCount(1, $document->query('//*[@id="birthdays"]'));
        $this->assertCount(1, $document->query('//table[contains(@class,"shift-calendar")]/following::*[@id="worker-receipts"]/following::*[@id="announcements"]'));

        $this->getJson(route('worker.today', ['week' => '2026-11-01']))->assertOk()
            ->assertJsonPath('date', '2026-10-26')
            ->assertJsonCount(1, 'announcements')
            ->assertJsonPath('announcements.0.title', 'הודעה של היום');
    }

    #[DataProvider('boundaryWeeks')]
    public function test_event_dates_cross_month_and_year_boundaries_without_extending_the_booking_window(string $requestedDate, string $sunday, string $thursday): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $this->createEvent($manager, $sunday, 'תחילת השבוע');
        $this->createEvent($manager, $thursday, 'סוף השבוע');

        $response = $this->actingAs($worker)->get(route('worker.home', ['week' => $requestedDate]));

        $response->assertOk();
        $document = $this->document($response->getContent());
        $this->assertSame(['תחילת השבוע'], $this->titlesUnderDate($document, $sunday));
        $this->assertSame(['סוף השבוע'], $this->titlesUnderDate($document, $thursday));
        $this->assertCount(25, $document->query('//button[@data-date and @data-period and @disabled]'));
        $this->assertCount(0, $document->query('//button[@data-date and @data-period and not(@disabled)]'));
    }

    public static function boundaryWeeks(): array
    {
        return [
            'month boundary beyond booking horizon' => ['2026-12-02', '2026-11-29', '2026-12-03'],
            'future year boundary' => ['2029-01-02', '2028-12-31', '2029-01-04'],
        ];
    }

    public function test_default_calendar_uses_the_israeli_week_when_utc_is_still_in_the_previous_month(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 22:30:00', 'UTC'));
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $this->createEvent($manager, '2026-10-31', 'השבוע הקודם בישראל');
        $this->createEvent($manager, '2026-11-01', 'ראשון בישראל');

        $response = $this->actingAs($worker)->get(route('worker.home'));

        $response->assertOk()->assertViewHas('receiptMonth', '2026-11');
        $document = $this->document($response->getContent());
        $this->assertSame(['ראשון בישראל'], $this->titlesUnderDate($document, '2026-11-01'));
        $response->assertDontSee('השבוע הקודם בישראל');
    }

    public function test_event_labels_do_not_close_a_date_and_remain_readable_after_an_explicit_block(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $this->createEvent($manager, '2026-10-27', 'חג עם משרד פתוח', 'holiday');

        $response = $this->actingAs($worker)->get(route('worker.home'));

        $response->assertOk();
        $document = $this->document($response->getContent());
        $this->assertSame(['חג עם משרד פתוח'], $this->titlesUnderDate($document, '2026-10-27'));
        $this->assertCount(5, $document->query('//button[@data-date="2026-10-27" and @data-period and not(@disabled)]'));
        $this->post(route('worker.shifts.store'), ['shift_date' => '2026-10-27', 'period' => 'full'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Shift::active()->count());

        // An event is informational. Only the separate manager block changes
        // availability, and the event's text should still remain readable.
        app(DateClosureService::class)->block($manager, '2026-10-27', 'סגירה מפורשת');
        $blockedPage = $this->get(route('worker.home'))->assertOk();
        $blockedDocument = $this->document($blockedPage->getContent());
        $this->assertSame(['חג עם משרד פתוח'], $this->titlesUnderDate($blockedDocument, '2026-10-27'));
        $this->assertCount(5, $blockedDocument->query('//button[@data-date="2026-10-27" and @data-period and @disabled]'));
        $this->assertSame(0, Shift::active()->count());
    }

    /** Create persisted date-only records without coupling read tests to manager forms. */
    private function createEvent(User $manager, string $date, string $title, string $kind = 'event', ?string $description = null): CalendarEvent
    {
        return CalendarEvent::create([
            'date' => $date,
            'title' => $title,
            'kind' => $kind,
            'description' => $description,
            'created_by' => $manager->id,
            'updated_by' => $manager->id,
        ]);
    }

    private function titlesUnderDate(DOMXPath $document, string $date): array
    {
        $header = $this->dateHeader($document, $date);
        $titles = [];

        foreach ($document->query('.//li[contains(@class,"calendar-date-event")]/bdi', $header) as $title) {
            $titles[] = trim($title->textContent);
        }

        return $titles;
    }

    private function dateHeader(DOMXPath $document, string $date): DOMElement
    {
        $headers = $document->query('//tr[contains(@class,"calendar-dates")]/th[time[@datetime="'.$date.'"]]');
        $this->assertCount(1, $headers, 'The viewed calendar must contain the requested date exactly once.');
        $header = $headers->item(0);
        $this->assertInstanceOf(DOMElement::class, $header);

        return $header;
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
