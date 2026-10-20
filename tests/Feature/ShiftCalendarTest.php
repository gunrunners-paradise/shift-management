<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DateClosureService;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesShiftRecords;
use Tests\TestCase;

class ShiftCalendarTest extends TestCase
{
    use CreatesShiftRecords, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Jerusalem'));
    }

    public function test_blade_renders_five_period_rows_and_sunday_to_thursday_without_javascript(): void
    {
        $worker = User::factory()->create();

        $response = $this->actingAs($worker)->get(route('worker.home'));

        $response->assertOk()
            ->assertSee('09:00–13:00')
            ->assertSee('10:00–14:00')
            ->assertSee('11:00–15:00')
            ->assertSee('12:00–16:00')
            ->assertSee('09:00–16:30')
            ->assertSee('ראשון')
            ->assertSee('חמישי')
            ->assertSee('יש לי קבלות החודש')
            ->assertSee('מי עובד איתי היום?')
            ->assertDontSee('חג לדוגמה היום')
            ->assertDontSee('localStorage', false);
        $document = $this->document($response->getContent());

        $this->assertCount(5, $document->query('//table[contains(@class,"shift-calendar")]/tbody/tr'));
        $this->assertCount(25, $document->query('//button[@data-date and @data-period]'));

        foreach (['2026-10-18', '2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22'] as $date) {
            $this->assertCount(5, $document->query('//button[@data-date="'.$date.'" and @data-period]'));
        }

        $this->assertCount(1, $document->query('//a[contains(@href,"week=2026-10-11")]'));
        $this->assertCount(1, $document->query('//a[contains(@href,"week=2026-10-25")]'));
    }

    public function test_chosen_day_has_one_selected_and_four_clickable_alternatives_while_blocked_day_disables_all_five(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $this->createShiftRecord($worker, '2026-10-20', '09_13');
        app(DateClosureService::class)->block($manager, '2026-10-21', 'סגירה');

        $response = $this->actingAs($worker)->get(route('worker.home'));
        $response->assertOk();
        $document = $this->document($response->getContent());

        $this->assertCount(1, $document->query('//button[@data-date="2026-10-20" and @aria-pressed="true" and not(@disabled)]'));
        $this->assertCount(4, $document->query('//button[@data-date="2026-10-20" and contains(@class,"shift-slot--alternative") and not(@disabled)]'));
        $this->assertCount(5, $document->query('//button[@data-date="2026-10-21" and contains(@class,"shift-slot--blocked") and @disabled]'));
        $this->assertCount(10, $document->query('//button[(@data-date="2026-10-18" or @data-date="2026-10-19") and @disabled]'));

        foreach ($document->query('//button[@data-date and @data-period]') as $button) {
            $this->assertNotEmpty($button->getAttribute('aria-label'), 'Every color state needs a readable accessible description.');
            $this->assertNotEmpty(trim($button->textContent), 'Status must remain understandable without color.');
        }
    }

    public function test_calendar_does_not_show_another_workers_selection_even_when_their_id_is_supplied(): void
    {
        $worker = User::factory()->create();
        $otherWorker = User::factory()->create(['first_name' => 'עובדפרטיאחר', 'last_name' => 'לאלהציג']);
        $this->createShiftRecord($otherWorker, '2026-10-20', 'full');

        $response = $this->actingAs($worker)->get(route('worker.home', ['worker_id' => $otherWorker->id]));

        $response->assertOk();
        $document = $this->document($response->getContent());
        $calendar = $document->query('//table[contains(@class,"shift-calendar")]')->item(0);

        // Stage 3 legitimately names colleagues below the calendar. The private
        // grid itself must still contain only the signed-in worker's choices.
        $this->assertStringNotContainsString($otherWorker->fullName(), $calendar->textContent);
        $this->assertCount(0, $document->query('//button[@data-period and @aria-pressed="true"]'));
    }

    #[DataProvider('readOnlyWeeks')]
    public function test_worker_can_browse_past_and_distant_weeks_as_read_only(string $week): void
    {
        $worker = User::factory()->create();

        $response = $this->actingAs($worker)->get(route('worker.home', ['week' => $week]));

        $response->assertOk();
        $document = $this->document($response->getContent());
        $this->assertCount(25, $document->query('//button[@data-date and @data-period and @disabled]'));
        $this->assertCount(0, $document->query('//button[@data-date and @data-period and not(@disabled)]'));
    }

    public static function readOnlyWeeks(): array
    {
        return [
            'past week' => ['2026-10-04'],
            'outside 28 day window' => ['2026-11-22'],
        ];
    }

    public function test_requested_week_is_normalized_to_sunday_and_does_not_redefine_actual_today(): void
    {
        $worker = User::factory()->create();

        $response = $this->actingAs($worker)->get(route('worker.home', ['week' => '2026-11-18']));

        $response->assertOk();
        $document = $this->document($response->getContent());
        $this->assertCount(5, $document->query('//button[@data-date="2026-11-15" and @data-period and not(@disabled)]'));
        $this->assertCount(5, $document->query('//button[@data-date="2026-11-19" and @data-period and @disabled]'));
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
