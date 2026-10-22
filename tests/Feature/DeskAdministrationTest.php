<?php

namespace Tests\Feature;

use App\Models\BlockedDate;
use App\Models\CalendarEvent;
use App\Models\Desk;
use App\Models\DeskReservation;
use App\Models\MonthlyReceipt;
use App\Models\User;
use App\Services\DeskManagementService;
use App\Services\DeskReservationService;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesDeskReservations;
use Tests\Concerns\CreatesShiftRecords;
use Tests\TestCase;

class DeskAdministrationTest extends TestCase
{
    use CreatesDeskReservations, CreatesShiftRecords, RefreshDatabase;

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

    #[DataProvider('invalidDeletionConfirmations')]
    public function test_desk_deletion_requires_explicit_confirmation(array $input): void
    {
        $manager = User::factory()->manager()->create();
        $desk = Desk::query()->firstOrFail();
        $reservation = $this->createDeskReservation(User::factory()->create(), $desk);

        $this->actingAs($manager)->delete(route('admin.desks.destroy', $desk), $input)
            ->assertSessionHasErrors('confirm_deletion');

        $this->assertTrue($desk->fresh()->is_active);
        $this->assertDatabaseHas('desk_reservations', ['id' => $reservation->id, 'cancelled_at' => null]);
    }

    public static function invalidDeletionConfirmations(): array
    {
        return [
            'missing acknowledgement' => [[]],
            'declined acknowledgement' => [['confirm_deletion' => '0']],
            'malformed acknowledgement' => [['confirm_deletion' => ['1']]],
        ];
    }

    public function test_desk_names_remain_unique_and_deleted_names_can_be_reused_for_new_identities(): void
    {
        $manager = User::factory()->manager()->create();
        $desks = Desk::query()->take(2)->get();
        $this->actingAs($manager)->post(route('admin.desks.store'), ['name' => $desks[0]->name])
            ->assertSessionHasErrors('name');
        $this->patch(route('admin.desks.update', $desks[1]), ['name' => $desks[0]->name])
            ->assertSessionHasErrors('name');

        $this->createDeskReservation(User::factory()->create(), $desks[0]);
        app(DeskManagementService::class)->delete($manager, $desks[0]);
        $this->flushSession();
        $this->post(route('admin.desks.store'), ['name' => $desks[0]->name])->assertSessionHasNoErrors();

        $replacement = Desk::active()->where('name', $desks[0]->name)->sole();
        $this->assertNotSame($desks[0]->id, $replacement->id);
        $this->assertNull($desks[0]->fresh());
        $this->assertDatabaseCount('desk_reservations', 0);
        $this->assertDatabaseCount('desks', 9);
    }

    #[DataProvider('deletionBoundaries')]
    public function test_deleting_a_desk_removes_past_current_future_and_cancelled_reservations(string $now): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 '.$now, 'Asia/Jerusalem'));
        $manager = User::factory()->manager()->create();
        $desk = Desk::query()->firstOrFail();
        $this->createDeskReservation(User::factory()->create(), $desk, '2025-10-19');
        $this->createDeskReservation(User::factory()->create(), $desk, '2026-10-20', '10:00', '11:00');
        $this->createDeskReservation(User::factory()->create(), $desk, '2026-10-21');
        $this->createDeskReservation(User::factory()->create(), $desk, '2026-10-21', '13:00', '14:00', [
            'cancelled_at' => now()->subDay(), 'cancelled_by' => $manager->id, 'cancellation_reason' => 'date_blocked',
        ]);

        $this->actingAs($manager)->delete(route('admin.desks.destroy', $desk), ['confirm_deletion' => '1'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseMissing('desks', ['id' => $desk->id]);
        $this->assertDatabaseCount('desks', 8);
        $this->assertDatabaseCount('desk_reservations', 0);
        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseHas('calendar_dates', ['date' => '2025-10-19']);
        $this->assertDatabaseHas('calendar_dates', ['date' => '2026-10-20']);
        $this->assertDatabaseHas('calendar_dates', ['date' => '2026-10-21']);
    }

    public static function deletionBoundaries(): array
    {
        return [
            'upcoming today' => ['09:00:00'],
            'exactly at start' => ['10:00:00'],
            'already in progress' => ['10:30:00'],
            'exactly at end' => ['11:00:00'],
            'after end' => ['12:00:00'],
        ];
    }

    public function test_deleting_a_legacy_inactive_desk_also_removes_its_history(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();
        $desk->forceFill(['is_active' => false, 'deactivated_at' => now(), 'deactivated_by' => $manager->id])->save();
        $this->createDeskReservation($worker, $desk, '2026-10-19', '09:00', '10:00', [
            'cancelled_at' => now(), 'cancelled_by' => $manager->id, 'cancellation_reason' => 'desk_deactivated',
        ]);

        $this->actingAs($manager)->delete(route('admin.desks.destroy', $desk), ['confirm_deletion' => '1'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseMissing('desks', ['id' => $desk->id]);
        $this->assertDatabaseCount('desk_reservations', 0);
        $this->assertDatabaseHas('users', ['id' => $worker->id, 'is_active' => true]);
    }

    public function test_desk_deletion_preserves_other_desks_accounts_shifts_receipts_and_shared_dates(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $otherWorker = User::factory()->create();
        $desks = Desk::query()->take(2)->get();
        $this->createDeskReservation($worker, $desks[0]);
        $this->createDeskReservation($otherWorker, $desks[0], '2026-10-21', '13:00', '14:00');
        $retainedReservation = $this->createDeskReservation($worker, $desks[1], '2026-10-22');
        $retainedShift = $this->createShiftRecord($worker, '2026-10-21', 'full');
        $receipt = MonthlyReceipt::create(['worker_id' => $worker->id, 'month' => '2026-10-01', 'has_receipts' => true]);
        BlockedDate::create(['date' => '2026-10-22', 'reason' => 'סגירה', 'blocked_by' => $manager->id]);
        $event = CalendarEvent::create([
            'title' => 'פגישה', 'date' => '2026-10-21', 'kind' => 'event',
            'created_by' => $manager->id, 'updated_by' => $manager->id,
        ]);
        $retainedDesk = $desks[1]->getRawOriginal();
        $reservationAttributes = $retainedReservation->getRawOriginal();
        $shiftAttributes = $retainedShift->getRawOriginal();

        app(DeskManagementService::class)->delete($manager, $desks[0]);

        $this->assertSame($retainedDesk, $desks[1]->fresh()->getRawOriginal());
        $this->assertSame($reservationAttributes, $retainedReservation->fresh()->getRawOriginal());
        $this->assertSame($shiftAttributes, $retainedShift->fresh()->getRawOriginal());
        $this->assertDatabaseHas('monthly_receipts', ['id' => $receipt->id, 'has_receipts' => true]);
        $this->assertDatabaseHas('calendar_events', ['id' => $event->id]);
        $this->assertDatabaseHas('blocked_dates', ['date' => '2026-10-22']);
        $this->assertDatabaseCount('calendar_dates', 2);
        $this->assertDatabaseCount('desk_reservations', 1);
        $this->assertDatabaseCount('users', 3);
        $this->assertTrue($worker->fresh()->is_active);
        $this->assertTrue($otherWorker->fresh()->is_active);
    }

    public function test_workers_and_guests_cannot_manage_desks(): void
    {
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();

        $this->post(route('admin.desks.store'), ['name' => 'עמדה נוספת'])->assertRedirect(route('login'));
        $this->patch(route('admin.desks.update', $desk), ['name' => 'שם חדש'])->assertRedirect(route('login'));
        $this->delete(route('admin.desks.destroy', $desk), ['confirm_deletion' => '1'])->assertRedirect(route('login'));

        $this->actingAs($worker)->post(route('admin.desks.store'), ['name' => 'עמדה נוספת'])->assertForbidden();
        $this->patch(route('admin.desks.update', $desk), ['name' => 'שם חדש'])->assertForbidden();
        $this->delete(route('admin.desks.destroy', $desk), ['confirm_deletion' => '1'])->assertForbidden();

        $this->assertTrue($desk->fresh()->is_active);
        $this->assertSame('עמדה 1', $desk->fresh()->name);
        $this->assertDatabaseCount('desks', 9);
    }

    #[DataProvider('staleManagerChanges')]
    public function test_management_service_rechecks_a_stale_managers_authority(array $changes): void
    {
        $manager = User::factory()->manager()->create();
        $desk = Desk::query()->firstOrFail();
        User::query()->whereKey($manager->id)->update($changes);

        $this->expectException(AuthorizationException::class);

        app(DeskManagementService::class)->delete($manager, $desk);
    }

    public static function staleManagerChanges(): array
    {
        return ['now inactive' => [['is_active' => false]], 'now a worker' => [['role' => 'worker']]];
    }

    public function test_an_inactive_manager_cannot_delete_a_desk_through_the_endpoint(): void
    {
        $manager = User::factory()->manager()->inactive()->create();
        $desk = Desk::query()->firstOrFail();

        $this->actingAs($manager)->delete(route('admin.desks.destroy', $desk), ['confirm_deletion' => '1'])
            ->assertRedirect(route('login'));

        $this->assertDatabaseHas('desks', ['id' => $desk->id]);
    }

    public function test_deleting_a_reserved_desk_frees_the_workers_daily_slot_for_another_desk(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $desks = Desk::query()->take(2)->get();
        $this->createDeskReservation($worker, $desks[0]);

        app(DeskManagementService::class)->delete($manager, $desks[0]);
        $replacement = app(DeskReservationService::class)->book($worker, $desks[1]->id, '2026-10-21', '13:00', '14:00');

        $this->assertSame($desks[1]->id, $replacement->desk_id);
        $this->assertNull($replacement->cancelled_at);
        $this->assertSame(1, DeskReservation::active()->where('worker_id', $worker->id)->count());
        $this->assertDatabaseCount('desk_reservations', 1);
    }

    public function test_stale_booking_edit_cancel_and_manager_forms_cannot_restore_deleted_rows(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $desks = Desk::query()->take(2)->get();
        $reservation = $this->createDeskReservation($worker, $desks[0]);
        $input = ['desk_id' => $desks[0]->id, 'reservation_date' => '2026-10-21', 'start_time' => '13:00', 'end_time' => '14:00'];
        app(DeskManagementService::class)->delete($manager, $desks[0]);

        $this->actingAs($worker)->post(route('worker.desks.store'), $input)->assertSessionHasErrors('desk_id');
        $this->patch(route('worker.desks.update', $reservation), $input)->assertNotFound();
        $this->delete(route('worker.desks.destroy', $reservation), ['confirm_cancellation' => '1'])->assertNotFound();
        $this->flushSession();
        $this->actingAs($manager)->delete(route('admin.desks.destroy', $desks[0]), ['confirm_deletion' => '1'])->assertNotFound();
        $this->patch(route('admin.desks.update', $desks[0]), ['name' => 'שם חדש'])->assertNotFound();

        $this->assertDatabaseMissing('desks', ['id' => $desks[0]->id]);
        $this->assertDatabaseCount('desk_reservations', 0);
    }

    #[DataProvider('staleWorkerOperations')]
    public function test_services_reject_already_loaded_reservations_after_the_desk_is_deleted(string $operation): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $desks = Desk::query()->take(2)->get();
        $reservation = $this->createDeskReservation($worker, $desks[0]);
        app(DeskManagementService::class)->delete($manager, $desks[0]);
        $rejected = false;

        try {
            $service = app(DeskReservationService::class);

            if ($operation === 'book') {
                $service->book($worker, $desks[0]->id, '2026-10-21', '13:00', '14:00');
            } elseif ($operation === 'edit') {
                $service->update($worker, $reservation, $desks[1]->id, '13:00', '14:00');
            } else {
                $service->cancel($worker, $reservation);
            }
        } catch (ValidationException|ModelNotFoundException) {
            $rejected = true;
        }

        $this->assertTrue($rejected);
        $this->assertDatabaseCount('desk_reservations', 0);
    }

    public static function staleWorkerOperations(): array
    {
        return ['booking' => ['book'], 'editing' => ['edit'], 'cancellation' => ['cancel']];
    }

    public function test_a_failure_after_reservation_removal_rolls_back_both_deletions(): void
    {
        $manager = User::factory()->manager()->create();
        $desk = Desk::query()->firstOrFail();
        $reservation = $this->createDeskReservation(User::factory()->create(), $desk);
        $injectedFailure = false;

        // Throw at the real parent delete, after its child rows were removed.
        // The service must restore those rows when its transaction rolls back.
        DB::listen(function (QueryExecuted $query) use (&$injectedFailure): void {
            if (! $injectedFailure && str_starts_with(strtolower($query->sql), 'delete from `desks`')) {
                $injectedFailure = true;
                throw new RuntimeException('Intentional test deletion failure.');
            }
        });

        try {
            app(DeskManagementService::class)->delete($manager, $desk);
            $this->fail('The injected database failure did not interrupt deletion.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Intentional test deletion failure.', $exception->getMessage());
        }

        $this->assertTrue($injectedFailure);
        $this->assertDatabaseHas('desks', ['id' => $desk->id, 'is_active' => true]);
        $this->assertDatabaseHas('desk_reservations', ['id' => $reservation->id, 'cancelled_at' => null]);

        // Rollback must release the shared manager gate for a later operation.
        app(DeskManagementService::class)->delete($manager, $desk);
        $this->assertDatabaseMissing('desks', ['id' => $desk->id]);
        $this->assertDatabaseCount('desk_reservations', 0);
    }

    public function test_the_manager_form_uses_native_deletion_with_acknowledgement_and_no_typed_credentials(): void
    {
        $manager = User::factory()->manager()->create();
        $desk = Desk::query()->firstOrFail();
        $response = $this->actingAs($manager)->get(route('admin.users.index'))->assertOk();
        $document = new DOMDocument;
        $previousErrors = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
        $xpath = new DOMXPath($document);
        $forms = $xpath->query('//form[@action="'.route('admin.desks.destroy', $desk).'" and .//input[@name="_method" and @value="DELETE"]]');

        $this->assertCount(1, $forms);
        $form = $forms->item(0);
        $this->assertSame('POST', strtoupper($form->getAttribute('method')));
        $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $form)->length);
        $this->assertSame(1, $xpath->query('.//input[@type="checkbox" and @name="confirm_deletion" and @required]', $form)->length);
        $this->assertSame(0, $xpath->query('.//input[not(@type="hidden") and not(@type="checkbox")]', $form)->length);
        $this->assertStringContainsString('כל ההזמנות', $form->textContent);
        $this->assertFalse(Route::has('admin.desks.deactivate'));
        $this->patch('/admin/desks/'.$desk->id.'/deactivate', ['confirm_deactivation' => '1'])->assertNotFound();
    }

    public function test_malformed_acknowledgement_is_reported_only_on_the_correct_desk_form(): void
    {
        $manager = User::factory()->manager()->create();
        $desks = Desk::query()->take(2)->get();
        $response = $this->actingAs($manager)->from(route('admin.users.index'))
            ->delete(route('admin.desks.destroy', $desks[0]), [
                'confirm_deletion' => ['malformed'], 'desk_form_id' => $desks[1]->id,
            ])->assertSessionHasErrors('confirm_deletion');
        $message = session('errors')->first('confirm_deletion');

        $this->assertSame((string) $desks[0]->id, (string) session()->getOldInput('desk_form_id'));
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->followRedirects($response)->assertOk()->assertSee($message);
        $this->assertDatabaseCount('desks', 9);
    }

    public function test_deleting_the_last_desk_leaves_valid_empty_pages_and_does_not_reseed(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();

        foreach (Desk::query()->get() as $desk) {
            app(DeskManagementService::class)->delete($manager, $desk);
        }

        $this->actingAs($manager)->get(route('admin.users.index'))->assertOk();
        $this->flushSession();
        $this->actingAs($worker)->get(route('worker.desks.index', ['date' => '2026-10-21']))->assertOk()
            ->assertDontSee('id="desk-booking-form"', false);
        $this->assertDatabaseCount('desks', 0);
        $this->assertDatabaseCount('desk_reservations', 0);
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
