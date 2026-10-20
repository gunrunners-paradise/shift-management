<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Shift;
use App\Models\User;
use App\Services\CalendarEventService;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesShiftRecords;
use Tests\TestCase;

class CalendarEventTest extends TestCase
{
    use CreatesShiftRecords, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Jerusalem'));
    }

    #[DataProvider('eventKinds')]
    public function test_manager_can_create_a_single_day_event_or_manually_named_holiday(string $kind): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->post(route('admin.events.store'), $this->attributes([
            'kind' => $kind,
            'description' => '',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('calendar_events', [
            'title' => 'מפגש צוות',
            'date' => '2026-10-20',
            'kind' => $kind,
            'description' => null,
            'created_by' => $manager->id,
            'updated_by' => $manager->id,
        ]);
    }

    public static function eventKinds(): array
    {
        return [['event'], ['holiday']];
    }

    public function test_manager_can_edit_an_event_while_preserving_its_identity_and_creator(): void
    {
        $creator = User::factory()->manager()->create();
        $editor = User::factory()->manager()->create();
        $event = $this->createEvent($creator);

        $this->actingAs($editor)->patch(route('admin.events.update', $event), $this->attributes([
            'title' => 'חג שנקבע ידנית',
            'event_date' => '2026-10-21',
            'kind' => 'holiday',
            'description' => 'הודעה מעודכנת',
        ]))->assertSessionHasNoErrors();

        $event->refresh();
        $this->assertSame('חג שנקבע ידנית', $event->title);
        $this->assertSame('2026-10-21', $event->date->format('Y-m-d'));
        $this->assertSame('holiday', $event->kind);
        $this->assertSame($creator->id, $event->created_by);
        $this->assertSame($editor->id, $event->updated_by);
        $this->assertDatabaseCount('calendar_events', 1);
    }

    public function test_deleting_an_event_requires_explicit_confirmation(): void
    {
        $manager = User::factory()->manager()->create();
        $event = $this->createEvent($manager);

        $this->actingAs($manager)->delete(route('admin.events.destroy', $event))
            ->assertSessionHasErrors('confirm_event_deletion');
        $this->assertDatabaseHas('calendar_events', ['id' => $event->id]);

        $this->delete(route('admin.events.destroy', $event), ['confirm_event_deletion' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('calendar_events', ['id' => $event->id]);
    }

    #[DataProvider('invalidEventFields')]
    public function test_invalid_or_multiday_event_fields_are_rejected(string $field, mixed $value): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->post(route('admin.events.store'), $this->attributes([$field => $value]))
            ->assertSessionHasErrors($field);

        $this->assertDatabaseCount('calendar_events', 0);
    }

    public static function invalidEventFields(): array
    {
        return [
            'missing title' => ['title', ''],
            'long title' => ['title', str_repeat('א', 101)],
            'invalid date' => ['event_date', '2026-02-30'],
            'timestamp instead of date' => ['event_date', '2026-10-20T10:00:00+03:00'],
            'unknown kind' => ['kind', 'automatic_holiday'],
            'long description' => ['description', str_repeat('א', 501)],
            'start date' => ['start_date', '2026-10-20'],
            'end date' => ['end_date', '2026-10-22'],
        ];
    }

    #[DataProvider('arrayEventFields')]
    public function test_array_event_input_redisplays_validation_feedback_without_a_server_error(string $field): void
    {
        $manager = User::factory()->manager()->create();

        $response = $this->from(route('admin.users.index'))->actingAs($manager)
            ->post(route('admin.events.store'), $this->attributes([$field => ['invalid input']]));

        $response->assertSessionHasErrors($field);
        $message = session('errors')->first($field);

        // Carry the response's session into the next request, as a browser does.
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->followRedirects($response)->assertOk()->assertSee($message);
        $this->assertDatabaseCount('calendar_events', 0);
    }

    public static function arrayEventFields(): array
    {
        return [['title'], ['description']];
    }

    public function test_worker_cannot_create_edit_or_delete_manager_events(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $event = $this->createEvent($manager);

        $this->actingAs($worker)->post(route('admin.events.store'), $this->attributes())->assertForbidden();
        $this->patch(route('admin.events.update', $event), $this->attributes(['title' => 'שינוי לא מורשה']))
            ->assertForbidden();
        $this->delete(route('admin.events.destroy', $event), ['confirm_event_deletion' => '1'])
            ->assertForbidden();

        $this->assertSame('מפגש צוות', $event->fresh()->title);
        $this->assertDatabaseCount('calendar_events', 1);
    }

    public function test_guests_and_deactivated_managers_cannot_write_events(): void
    {
        $manager = User::factory()->manager()->create();
        $event = $this->createEvent($manager);

        $this->post(route('admin.events.store'), $this->attributes())->assertRedirect(route('login'));
        $this->patch(route('admin.events.update', $event), $this->attributes())->assertRedirect(route('login'));
        $this->delete(route('admin.events.destroy', $event), ['confirm_event_deletion' => '1'])
            ->assertRedirect(route('login'));

        $this->actingAs($manager);
        User::query()->whereKey($manager->id)->update(['is_active' => false]);
        $this->post(route('admin.events.store'), $this->attributes())->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('calendar_events', 1);
    }

    public function test_event_service_rechecks_a_stale_deactivated_manager(): void
    {
        $manager = User::factory()->manager()->create();
        User::query()->whereKey($manager->id)->update(['is_active' => false]);

        $this->expectException(AuthorizationException::class);

        app(CalendarEventService::class)->create($manager, $this->attributes());
    }

    public function test_events_and_holidays_neither_cancel_existing_shifts_nor_prevent_new_selections(): void
    {
        $manager = User::factory()->manager()->create();
        $firstWorker = User::factory()->create();
        $secondWorker = User::factory()->create();
        $existingShift = $this->createShiftRecord($firstWorker, '2026-10-20', '09_13');
        $this->createEvent($manager, ['kind' => 'event']);
        $this->createEvent($manager, ['kind' => 'holiday', 'title' => 'חג ידני']);

        $this->actingAs($secondWorker)->post(route('worker.shifts.store'), [
            'shift_date' => '2026-10-20', 'period' => 'full',
        ])->assertSessionHasNoErrors();

        $this->assertNull($existingShift->fresh()->cancelled_at);
        $this->assertSame(2, Shift::active()->count());
        $this->assertDatabaseCount('blocked_dates', 0);
    }

    public function test_only_actual_todays_events_are_announced_below_the_calendar_and_in_the_json_refresh(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create(['birth_date' => '2000-10-20']);
        $this->createEvent($manager, ['title' => 'מפגש היום']);
        $this->createEvent($manager, ['title' => 'חג היום', 'kind' => 'holiday', 'description' => null]);
        $this->createEvent($manager, ['title' => 'אירוע אתמול', 'event_date' => '2026-10-19']);
        $this->createEvent($manager, ['title' => 'אירוע מחר', 'event_date' => '2026-10-21']);

        $response = $this->actingAs($worker)->getJson(route('worker.today', ['week' => '2026-11-22']));

        $response->assertOk()->assertJsonPath('date', '2026-10-20');
        $announcements = $response->json('announcements');
        $this->assertEqualsCanonicalizing(['מפגש היום', 'חג היום'], array_column($announcements, 'title'));

        foreach ($announcements as $announcement) {
            $this->assertEqualsCanonicalizing(['title', 'description', 'kind'], array_keys($announcement));
        }

        $page = $this->get(route('worker.home', ['week' => '2026-11-22']))->assertOk();
        $document = $this->document($page->getContent());
        $this->assertCount(1, $document->query('//table[contains(@class,"shift-calendar")]/following::*[@id="announcements"]'));
        $notice = $document->query('//*[@id="announcements"]')->item(0);
        $this->assertStringContainsString('מפגש היום', $notice->textContent);
        $this->assertStringContainsString('חג היום', $notice->textContent);
        $this->assertStringNotContainsString('אירוע אתמול', $notice->textContent);
        $this->assertStringNotContainsString('אירוע מחר', $notice->textContent);
        $this->assertCount(1, $document->query('//*[@id="birthdays"]'));
    }

    public function test_event_announcements_use_the_israeli_date_when_utc_is_still_yesterday(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-17 21:30:00', 'UTC')->setTimezone('Asia/Jerusalem'));
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $this->createEvent($manager, ['title' => 'אירוע ישראלי היום', 'event_date' => '2026-10-18']);
        $this->createEvent($manager, ['title' => 'אתמול בישראל', 'event_date' => '2026-10-17']);

        $response = $this->actingAs($worker)->getJson(route('worker.today'));

        $response->assertOk();
        $this->assertSame(['אירוע ישראלי היום'], array_column($response->json('announcements'), 'title'));
    }

    public function test_titles_and_descriptions_are_escaped_on_both_manager_and_worker_pages(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $this->createEvent($manager, [
            'title' => '<script>alert(1)</script>',
            'description' => '<img src=x onerror=alert(2)>',
        ]);

        foreach ([[$manager, 'admin.users.index'], [$worker, 'worker.home']] as [$actor, $route]) {
            $this->flushSession();
            $this->actingAs($actor)->get(route($route))->assertOk()
                ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
                ->assertSee('&lt;img src=x onerror=alert(2)&gt;', false)
                ->assertDontSee('<script>alert(1)</script>', false)
                ->assertDontSee('<img src=x onerror=alert(2)>', false);
        }
    }

    private function createEvent(User $manager, array $overrides = []): CalendarEvent
    {
        return app(CalendarEventService::class)->create($manager, $this->attributes($overrides));
    }

    private function attributes(array $overrides = []): array
    {
        return array_replace([
            'title' => 'מפגש צוות',
            'event_date' => '2026-10-20',
            'kind' => 'event',
            'description' => 'מידע לצוות',
        ], $overrides);
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
