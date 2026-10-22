<?php

namespace Tests\Feature;

use App\Models\Desk;
use App\Models\DeskReservation;
use App\Models\User;
use App\Services\DeskManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesDeskReservations;
use Tests\TestCase;

class DeskAdministrationTest extends TestCase
{
    use CreatesDeskReservations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:30:00', 'Asia/Jerusalem'));
    }

    public function test_migration_creates_nine_active_desks_and_does_not_reseed_them_on_page_visits(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $originalIds = Desk::query()->orderBy('id')->pluck('id')->all();

        $this->assertCount(9, $originalIds);
        $this->assertSame(9, Desk::query()->where('is_active', true)->count());
        $this->assertSame(range(1, 9), $originalIds);
        $this->assertSame(array_map(fn (int $number): string => 'עמדה '.$number, range(1, 9)), Desk::query()->orderBy('id')->pluck('name')->all());

        $this->actingAs($manager)->get(route('admin.users.index'))->assertOk();
        $this->flushSession();
        $this->actingAs($worker)->get(route('worker.desks.index'))->assertOk();
        $this->get(route('worker.desks.index'))->assertOk();

        $this->assertSame($originalIds, Desk::query()->orderBy('id')->pluck('id')->all());
        $this->assertDatabaseCount('desks', 9);
    }

    public function test_manager_can_add_and_rename_a_desk_without_changing_its_history_identity(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();

        $this->actingAs($manager)->post(route('admin.desks.store'), ['name' => 'עמדת חלון'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $desk = Desk::query()->where('name', 'עמדת חלון')->sole();
        $reservation = $this->createDeskReservation($worker, $desk);

        $this->patch(route('admin.desks.update', $desk), ['name' => 'עמדת גינה'])
            ->assertSessionHasNoErrors();

        $this->assertSame('עמדת גינה', $desk->fresh()->name);
        $this->assertTrue($desk->fresh()->is_active);
        $this->assertSame($desk->id, $reservation->fresh()->desk_id);
        $this->assertNull($reservation->fresh()->cancelled_at);
        $this->assertDatabaseCount('desks', 10);
    }

    #[DataProvider('invalidDeskNames')]
    public function test_manager_desk_names_are_validated(mixed $name): void
    {
        $manager = User::factory()->manager()->create();
        $desk = Desk::query()->firstOrFail();

        $this->actingAs($manager)->post(route('admin.desks.store'), ['name' => $name])
            ->assertSessionHasErrors('name');
        $this->patch(route('admin.desks.update', $desk), ['name' => $name])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('desks', 9);
        $this->assertSame('עמדה 1', $desk->fresh()->name);
    }

    public static function invalidDeskNames(): array
    {
        return [
            'empty name' => [''],
            'only whitespace' => ['   '],
            'over sixty characters' => [str_repeat('א', 61)],
            'array instead of text' => [['עמדה']],
        ];
    }

    public function test_desk_deactivation_requires_explicit_confirmation(): void
    {
        $manager = User::factory()->manager()->create();
        $desk = Desk::query()->firstOrFail();

        $this->actingAs($manager)->patch(route('admin.desks.deactivate', $desk))
            ->assertSessionHasErrors('confirm_deactivation');

        $this->assertTrue($desk->fresh()->is_active);
    }

    public function test_active_desk_names_remain_unique_but_inactive_names_can_be_reused_for_new_identities(): void
    {
        $manager = User::factory()->manager()->create();
        $desks = Desk::query()->take(2)->get();
        $this->actingAs($manager)->post(route('admin.desks.store'), ['name' => $desks[0]->name])
            ->assertSessionHasErrors('name');
        $this->patch(route('admin.desks.update', $desks[1]), ['name' => $desks[0]->name])
            ->assertSessionHasErrors('name');

        app(DeskManagementService::class)->deactivate($manager, $desks[0]);
        $this->flushSession();
        $this->post(route('admin.desks.store'), ['name' => $desks[0]->name])->assertSessionHasNoErrors();

        $replacement = Desk::active()->where('name', $desks[0]->name)->sole();
        $this->assertNotSame($desks[0]->id, $replacement->id);
        $this->assertFalse($desks[0]->fresh()->is_active);
        $this->assertDatabaseCount('desks', 10);
    }

    #[DataProvider('deactivationBoundaries')]
    public function test_desk_deactivation_immediately_cancels_running_and_future_bookings_but_retains_completed_history(string $now, bool $cancelToday): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 '.$now, 'Asia/Jerusalem'));
        $manager = User::factory()->manager()->create();
        $desk = Desk::query()->firstOrFail();
        $past = $this->createDeskReservation(User::factory()->create(), $desk, '2026-10-19');
        $today = $this->createDeskReservation(User::factory()->create(), $desk, '2026-10-20', '10:00', '11:00');
        $future = $this->createDeskReservation(User::factory()->create(), $desk, '2026-10-21');
        $cancelled = $this->createDeskReservation(User::factory()->create(), $desk, '2026-10-21', '13:00', '14:00', [
            'cancelled_at' => now()->subDay(), 'cancelled_by' => $manager->id, 'cancellation_reason' => 'date_blocked',
        ]);
        $originalCancellation = $cancelled->cancelled_at;

        $this->actingAs($manager)->patch(route('admin.desks.deactivate', $desk), ['confirm_deactivation' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($desk->fresh()->is_active);
        $this->assertSame($manager->id, $desk->fresh()->deactivated_by);
        $this->assertNotNull($desk->fresh()->deactivated_at);
        $this->assertNull($past->fresh()->cancelled_at);
        $this->assertSame($cancelToday, $today->fresh()->cancelled_at !== null);
        $this->assertSame('desk_deactivated', $future->fresh()->cancellation_reason);
        $this->assertSame($manager->id, $future->fresh()->cancelled_by);
        $this->assertTrue($originalCancellation->equalTo($cancelled->fresh()->cancelled_at));
        $this->assertSame('date_blocked', $cancelled->fresh()->cancellation_reason);
        $this->assertDatabaseCount('desk_reservations', 4);
        $this->assertDatabaseCount('desks', 9);

        if ($cancelToday) {
            $this->assertSame('desk_deactivated', $today->fresh()->cancellation_reason);
            $this->assertSame($manager->id, $today->fresh()->cancelled_by);
        }
    }

    public static function deactivationBoundaries(): array
    {
        return [
            'upcoming today' => ['09:00:00', true],
            'exactly at start' => ['10:00:00', true],
            'already in progress' => ['10:30:00', true],
            'exactly at end is completed' => ['11:00:00', false],
            'after end is completed' => ['12:00:00', false],
        ];
    }

    public function test_repeated_desk_deactivation_keeps_original_cancellation_and_allows_no_new_reservation(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();
        $reservation = $this->createDeskReservation($worker, $desk);
        $service = app(DeskManagementService::class);
        $service->deactivate($manager, $desk);
        $cancelledAt = $reservation->fresh()->cancelled_at;
        $deactivatedAt = $desk->fresh()->deactivated_at;

        $this->travel(1)->minutes();
        $service->deactivate($manager, $desk);

        $this->assertTrue($cancelledAt->equalTo($reservation->fresh()->cancelled_at));
        $this->assertTrue($deactivatedAt->equalTo($desk->fresh()->deactivated_at));
        $this->actingAs($worker)->post(route('worker.desks.store'), [
            'desk_id' => $desk->id, 'reservation_date' => '2026-10-21', 'start_time' => '13:00', 'end_time' => '14:00',
        ])->assertSessionHasErrors('desk_id');
        $this->assertSame(0, DeskReservation::active()->count());
        $this->assertDatabaseCount('desk_reservations', 1);
    }

    public function test_deactivation_rechecks_completion_after_waiting_for_the_desk_lock(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:59:59', 'Asia/Jerusalem'));
        $manager = User::factory()->manager()->create();
        $desk = Desk::query()->firstOrFail();
        $reservation = $this->createDeskReservation(User::factory()->create(), $desk, '2026-10-20', '10:00', '11:00');
        $advancedClock = false;

        DB::listen(function (QueryExecuted $query) use (&$advancedClock): void {
            $sql = strtolower($query->sql);

            if ($advancedClock || ! str_contains($sql, '`desks`') || ! str_contains($sql, 'for update')) {
                return;
            }

            $advancedClock = true;
            $this->travelTo(CarbonImmutable::parse('2026-10-20 11:00:00', 'Asia/Jerusalem'));
        });

        app(DeskManagementService::class)->deactivate($manager, $desk);

        $this->assertTrue($advancedClock);
        $this->assertFalse($desk->fresh()->is_active);
        $this->assertNull($reservation->fresh()->cancelled_at);
    }

    public function test_workers_and_guests_cannot_manage_desks(): void
    {
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();

        $this->post(route('admin.desks.store'), ['name' => 'עמדה נוספת'])->assertRedirect(route('login'));
        $this->patch(route('admin.desks.update', $desk), ['name' => 'שם חדש'])->assertRedirect(route('login'));
        $this->patch(route('admin.desks.deactivate', $desk), ['confirm_deactivation' => '1'])->assertRedirect(route('login'));

        $this->actingAs($worker)->post(route('admin.desks.store'), ['name' => 'עמדה נוספת'])->assertForbidden();
        $this->patch(route('admin.desks.update', $desk), ['name' => 'שם חדש'])->assertForbidden();
        $this->patch(route('admin.desks.deactivate', $desk), ['confirm_deactivation' => '1'])->assertForbidden();

        $this->assertTrue($desk->fresh()->is_active);
        $this->assertSame('עמדה 1', $desk->fresh()->name);
        $this->assertDatabaseCount('desks', 9);
    }

    public function test_management_service_rechecks_a_stale_managers_authority(): void
    {
        $manager = User::factory()->manager()->create();
        $desk = Desk::query()->firstOrFail();
        User::query()->whereKey($manager->id)->update(['is_active' => false]);

        $this->expectException(AuthorizationException::class);

        app(DeskManagementService::class)->deactivate($manager, $desk);
    }

    public function test_desk_labels_are_escaped_in_both_manager_and_worker_views(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        app(DeskManagementService::class)->create($manager, '<script>alert(1)</script>');

        foreach (['admin.users.index' => $manager, 'worker.desks.index' => $worker] as $route => $viewer) {
            $this->flushSession();
            $this->actingAs($viewer)->get(route($route))->assertOk()
                ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
                ->assertDontSee('<script>alert(1)</script>', false);
        }
    }
}
