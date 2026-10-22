<?php

namespace Tests\Feature;

use App\Models\Desk;
use App\Models\User;
use App\Services\DateClosureService;
use App\Services\DeskManagementService;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesDeskReservations;
use Tests\TestCase;

class DeskPageTest extends TestCase
{
    use CreatesDeskReservations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00:00', 'Asia/Jerusalem'));
    }

    public function test_server_renders_nine_desks_native_booking_and_real_day_navigation_without_javascript(): void
    {
        $worker = User::factory()->create();

        $response = $this->actingAs($worker)->get(route('worker.desks.index', ['date' => '2026-10-21']));

        $response->assertOk()->assertSee('הזמנת עמדת עבודה')->assertDontSee('localStorage', false)
            ->assertDontSee('worker-desks-prototype.html', false);
        $document = $this->document($response->getContent());
        $this->assertCount(9, $document->query('//*[@id="desks-grid"]/a'));
        $this->assertCount(1, $document->query('//form[@id="desk-booking-form" and @method="POST"]'));
        $this->assertCount(1, $document->query('//form[@id="desk-booking-form"]//input[@name="reservation_date" and @value="2026-10-21"]'));
        $this->assertCount(2, $document->query('//form[@id="desk-booking-form"]//input[@type="time" and @step="1800"]'));
        $this->assertCount(1, $document->query('//nav[contains(@class,"desk-date-navigation")]/a[@rel="prev" and contains(@href,"date=2026-10-20")]'));
        $this->assertCount(1, $document->query('//nav[contains(@class,"desk-date-navigation")]/a[@rel="next" and contains(@href,"date=2026-10-22")]'));
    }

    public function test_busy_intervals_exclude_cancelled_records_and_do_not_reveal_colleagues_identity(): void
    {
        $viewer = User::factory()->create();
        $otherWorker = User::factory()->create(['username' => 'private.colleague', 'first_name' => 'שםפרטישלאנחשף', 'last_name' => 'שםמשפחהפרטי']);
        $desk = Desk::query()->firstOrFail();
        $this->createDeskReservation($otherWorker, $desk, '2026-10-21', '10:00', '11:00');
        $this->createDeskReservation($otherWorker, $desk, '2026-10-21', '13:00', '14:00', [
            'cancelled_at' => now(), 'cancelled_by' => $otherWorker->id, 'cancellation_reason' => 'worker_cancelled',
        ]);

        $response = $this->actingAs($viewer)->get(route('worker.desks.index', ['date' => '2026-10-21', 'desk_id' => $desk->id]));

        $response->assertOk()->assertSee('10:00–11:00')->assertDontSee('13:00–14:00')
            ->assertDontSee($otherWorker->username)->assertDontSee($otherWorker->first_name)->assertDontSee($otherWorker->last_name);
        $document = $this->document($response->getContent());
        $this->assertCount(1, $document->query('//ul[contains(@class,"desk-busy-list")]/li[contains(@class,"desk-busy-entry")]'));
        $this->assertCount(0, $document->query('//*[@data-worker-id]'));
        $ownPanel = $document->query('//*[@id="my-desk-reservations"]')->item(0);
        $this->assertStringNotContainsString('10:00–11:00', $ownPanel->textContent);
    }

    public function test_own_active_and_cancelled_history_is_visible_with_native_confirmation_before_start(): void
    {
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();
        $active = $this->createDeskReservation($worker, $desk);
        $this->createDeskReservation($worker, $desk, '2026-10-21', '13:00', '14:00', [
            'cancelled_at' => now(), 'cancelled_by' => $worker->id, 'cancellation_reason' => 'worker_cancelled',
        ]);

        $response = $this->actingAs($worker)->get(route('worker.desks.index', ['date' => '2026-10-21']));

        $response->assertOk()->assertSee('עריכת ההזמנה שלי')->assertSee('ההזמנה בוטלה. הרשומה נשמרת בהיסטוריה.');
        $document = $this->document($response->getContent());
        $this->assertCount(2, $document->query('//*[@id="my-desk-reservations"]//li[contains(@class,"desk-own-card")]'));
        $this->assertCount(1, $document->query('//form[@id="desk-booking-form"]//input[@name="_method" and @value="PATCH"]'));
        $this->assertCount(1, $document->query('//*[@id="my-desk-reservations"]//form//input[@name="confirm_cancellation" and @required]'));
        $this->assertStringContainsString(route('worker.desks.destroy', $active), $response->getContent());
    }

    public function test_refreshing_a_selected_desk_after_deactivation_falls_back_without_losing_own_history(): void
    {
        $worker = User::factory()->create();
        $manager = User::factory()->manager()->create();
        $desk = Desk::query()->firstOrFail();
        $reservation = $this->createDeskReservation($worker, $desk);
        $selectedDeskUrl = route('worker.desks.index', ['date' => '2026-10-21', 'desk_id' => $desk->id]);
        $this->actingAs($worker)->get($selectedDeskUrl)->assertOk();

        app(DeskManagementService::class)->deactivate($manager, $desk);
        $response = $this->get($selectedDeskUrl);

        $response->assertOk()
            ->assertViewHas('selectedDesk', fn (Desk $selectedDesk): bool => $selectedDesk->is_active && $selectedDesk->id !== $desk->id)
            ->assertSee('ההזמנה בוטלה. הרשומה נשמרת בהיסטוריה.');
        $document = $this->document($response->getContent());
        $grid = $document->query('//*[@id="desks-grid"]')->item(0);
        $this->assertCount(8, $document->query('//*[@id="desks-grid"]/a'));
        $this->assertStringNotContainsString($desk->name, $grid->textContent);
        $this->assertSame('desk_deactivated', $reservation->fresh()->cancellation_reason);
        $this->assertDatabaseCount('desk_reservations', 1);
    }

    #[DataProvider('readOnlyDates')]
    public function test_past_weekend_and_distant_dates_are_browsable_without_mutation_forms(string $date): void
    {
        $worker = User::factory()->create();

        $response = $this->actingAs($worker)->get(route('worker.desks.index', ['date' => $date]));

        $response->assertOk();
        $document = $this->document($response->getContent());
        $this->assertCount(9, $document->query('//*[@id="desks-grid"]/button[@disabled]'));
        $this->assertCount(0, $document->query('//*[@id="desks-grid"]/a'));
        $this->assertCount(0, $document->query('//form[@id="desk-booking-form"]'));
    }

    public static function readOnlyDates(): array
    {
        return ['past' => ['2026-10-19'], 'Friday' => ['2026-10-23'], 'Saturday' => ['2026-10-24'], 'outside horizon' => ['2026-11-18']];
    }

    public function test_blocked_date_and_started_reservation_have_no_edit_or_cancel_controls(): void
    {
        $worker = User::factory()->create();
        $manager = User::factory()->manager()->create();
        $desk = Desk::query()->firstOrFail();
        $this->createDeskReservation($worker, $desk, '2026-10-20', '09:00', '10:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00', 'Asia/Jerusalem'));

        $response = $this->actingAs($worker)->get(route('worker.desks.index'));
        $response->assertOk();
        $document = $this->document($response->getContent());
        $this->assertCount(0, $document->query('//form[@id="desk-booking-form"]'));
        $this->assertCount(0, $document->query('//*[@id="my-desk-reservations"]//input[@name="confirm_cancellation"]'));

        app(DateClosureService::class)->block($manager, '2026-10-21');
        $response = $this->get(route('worker.desks.index', ['date' => '2026-10-21']));
        $response->assertOk()->assertSee('התאריך חסום');
        $document = $this->document($response->getContent());
        $this->assertCount(9, $document->query('//*[@id="desks-grid"]/button[@disabled and @aria-describedby="desk-date-restriction"]'));
        $this->assertCount(0, $document->query('//*[@id="desks-grid"]/a'));
        $this->assertCount(0, $document->query('//*[@id="desks-grid"]//*[contains(@class,"is-selected")]'));
        $this->assertCount(0, $document->query('//form[@id="desk-booking-form"]'));
        $response->assertDontSee('ללא הזמנות ביום זה')->assertSee('לא ניתן להזמין ביום זה');

        app(DateClosureService::class)->unblock($manager, '2026-10-21');
        $reopened = $this->get(route('worker.desks.index', ['date' => '2026-10-21']))->assertOk();
        $document = $this->document($reopened->getContent());
        $this->assertCount(9, $document->query('//*[@id="desks-grid"]/a'));
        $this->assertCount(0, $document->query('//*[@id="desks-grid"]/button[@disabled]'));
    }

    public function test_worker_header_has_a_real_active_desks_link_and_profile_only_in_account_dropdown(): void
    {
        $worker = User::factory()->create();

        $response = $this->actingAs($worker)->get(route('worker.desks.index'));

        $response->assertOk();
        $document = $this->document($response->getContent());
        $this->assertCount(1, $document->query('//*[@id="site-menu"]//a[@href="'.route('worker.desks.index').'" and @aria-current="page"]'));
        $this->assertCount(0, $document->query('//*[@id="site-menu"]//a[@href="'.route('profile.edit').'"]'));
        $this->assertCount(1, $document->query('//*[@id="account-menu"]//a[@href="'.route('profile.edit').'"]'));
    }

    public function test_malformed_input_redisplays_hebrew_validation_without_breaking_the_desk_form(): void
    {
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();

        $response = $this->actingAs($worker)->from(route('worker.desks.index', ['date' => '2026-10-21']))
            ->post(route('worker.desks.store'), [
                'reservation_date' => '2026-10-21', 'desk_id' => $desk->id,
                'start_time' => ['09:00'], 'end_time' => '10:00',
            ]);

        $response->assertSessionHasErrors('start_time');
        $message = session('errors')->first('start_time');
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->followRedirects($response)->assertOk()->assertSee($message);
        $this->assertDatabaseCount('desk_reservations', 0);
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
