<?php

namespace Tests\Feature;

use App\Models\BlockedDate;
use App\Models\CalendarEvent;
use App\Models\Desk;
use App\Models\MonthlyReceipt;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\AccountManagementService;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesDeskReservations;
use Tests\Concerns\CreatesShiftRecords;
use Tests\TestCase;

class PermanentAccountDeletionTest extends TestCase
{
    // Real commits exercise avatar cleanup; RefreshDatabase would defer it.
    use CreatesDeskReservations, CreatesShiftRecords, DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00', 'Asia/Jerusalem'));
        Storage::fake('local');
    }

    /** Register fixture cleanup before DatabaseMigrations registers rollback. */
    protected function afterRefreshingDatabase(): void
    {
        $this->beforeApplicationDestroyed(function (): void {
            // Deleting a manager deliberately leaves nullable actor identities.
            // The production migration correctly refuses to make them required
            // again. Remove only these test fixtures after all assertions so
            // the normal migration teardown can run without weakening that guard.
            DB::table('blocked_dates')->whereNull('blocked_by')->delete();
            DB::table('calendar_events')->whereNull('created_by')->orWhereNull('updated_by')->delete();
        });
    }

    public function test_manager_permanently_deletes_an_inactive_worker_and_all_of_their_history(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->inactive()->create();
        $otherWorker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();
        $photoPath = $this->attachAvatar($worker);

        // Permanent deletion includes completed, canceled and future records.
        $this->createShiftRecord($worker, '2026-10-20');
        $this->createShiftRecord($worker, '2026-10-22', 'full', [
            'cancelled_at' => now(), 'cancelled_by' => $manager->id, 'cancellation_reason' => 'worker_deactivated',
        ]);
        $this->createShiftRecord($worker, '2026-10-25');
        $this->createDeskReservation($worker, $desk, '2026-10-20');
        $this->createDeskReservation($worker, $desk, '2026-10-22', '09:00', '10:00', [
            'cancelled_at' => now(), 'cancelled_by' => $manager->id, 'cancellation_reason' => 'worker_deactivated',
        ]);
        $this->createDeskReservation($worker, $desk, '2026-10-25');
        $this->createReceipt($worker, '2026-09-01', false);
        $this->createReceipt($worker, '2026-10-01', true);
        $this->insertSession('deleted-worker-first-session', $worker);
        $this->insertSession('deleted-worker-second-session', $worker);

        $otherShift = $this->createShiftRecord($otherWorker, '2026-10-20');
        $otherReservation = $this->createDeskReservation($otherWorker, $desk, '2026-10-20', '13:00', '14:00');
        $otherReceipt = $this->createReceipt($otherWorker, '2026-10-01', true);
        $this->insertSession('retained-worker-session', $otherWorker);

        $this->actingAs($manager)->delete(route('admin.users.destroy', $worker), $this->confirmation())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseMissing('users', ['id' => $worker->id]);

        foreach (['shifts', 'desk_reservations', 'monthly_receipts'] as $table) {
            $this->assertDatabaseMissing($table, ['worker_id' => $worker->id]);
        }

        $this->assertDatabaseMissing('sessions', ['user_id' => $worker->id]);
        Storage::disk('local')->assertMissing($photoPath);
        $this->assertDatabaseHas('users', ['id' => $manager->id, 'is_active' => true]);
        $this->assertDatabaseHas('users', ['id' => $otherWorker->id, 'is_active' => true]);
        $this->assertDatabaseHas('shifts', ['id' => $otherShift->id, 'cancelled_at' => null]);
        $this->assertDatabaseHas('desk_reservations', ['id' => $otherReservation->id, 'cancelled_at' => null]);
        $this->assertDatabaseHas('monthly_receipts', ['id' => $otherReceipt->id, 'has_receipts' => true]);
        $this->assertDatabaseHas('sessions', ['id' => 'retained-worker-session']);
        $this->assertDatabaseHas('calendar_dates', ['date' => '2026-10-25']);
        $this->assertDatabaseCount('desks', 9);
        $this->assertAuthenticatedAs($manager);
    }

    public function test_deleting_an_inactive_manager_keeps_shared_records_and_other_workers_history(): void
    {
        $manager = User::factory()->manager()->create();
        $formerManager = User::factory()->manager()->inactive()->create();
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();
        $cancellation = [
            'cancelled_at' => now(),
            'cancelled_by' => $formerManager->id,
            'cancellation_reason' => 'date_blocked',
            'cancellation_note' => 'סגירת משרד',
        ];
        $shift = $this->createShiftRecord($worker, '2026-10-25', '10_14', $cancellation);
        $reservation = $this->createDeskReservation($worker, $desk, '2026-10-25', '09:00', '10:00', $cancellation);
        BlockedDate::create(['date' => '2026-10-25', 'reason' => 'סגירת משרד', 'blocked_by' => $formerManager->id]);
        $event = CalendarEvent::create([
            'title' => 'מפגש צוות', 'date' => '2026-10-25', 'kind' => 'event',
            'created_by' => $formerManager->id, 'updated_by' => $manager->id,
        ]);
        $holiday = CalendarEvent::create([
            'title' => 'חג', 'date' => '2026-10-26', 'kind' => 'holiday',
            'created_by' => $manager->id, 'updated_by' => $formerManager->id,
        ]);
        $desk->forceFill(['is_active' => false, 'deactivated_at' => now(), 'deactivated_by' => $formerManager->id])->save();
        $this->insertSession('former-manager-session', $formerManager);

        $this->actingAs($manager)->delete(route('admin.users.destroy', $formerManager), $this->confirmation())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseMissing('users', ['id' => $formerManager->id]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $formerManager->id]);
        $this->assertDatabaseHas('shifts', [
            'id' => $shift->id, 'worker_id' => $worker->id, 'period' => '10_14',
            'cancelled_by' => null, 'cancellation_reason' => 'date_blocked', 'cancellation_note' => 'סגירת משרד',
        ]);
        $this->assertDatabaseHas('desk_reservations', [
            'id' => $reservation->id, 'worker_id' => $worker->id, 'cancelled_by' => null,
            'cancellation_reason' => 'date_blocked', 'cancellation_note' => 'סגירת משרד',
        ]);
        $this->assertDatabaseHas('blocked_dates', ['date' => '2026-10-25', 'reason' => 'סגירת משרד', 'blocked_by' => null]);
        $this->assertDatabaseHas('calendar_events', ['id' => $event->id, 'title' => 'מפגש צוות', 'created_by' => null, 'updated_by' => $manager->id]);
        $this->assertDatabaseHas('calendar_events', ['id' => $holiday->id, 'created_by' => $manager->id, 'updated_by' => null]);
        $this->assertDatabaseHas('desks', ['id' => $desk->id, 'is_active' => false, 'deactivated_by' => null]);
        $this->assertNotNull($shift->fresh()->cancelled_at);
        $this->assertNotNull($reservation->fresh()->cancelled_at);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('desks', 9);

        // Nullable audit identities must also render safely on the dashboard.
        $this->get(route('admin.users.index'))->assertOk()->assertSee('מפגש צוות')->assertSee('סגירת משרד');
    }

    #[DataProvider('invalidConfirmations')]
    public function test_permanent_deletion_requires_explicit_acknowledgement(array $input): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->inactive()->create();
        $photoPath = $this->attachAvatar($worker);

        $this->actingAs($manager)->from(route('admin.users.index'))
            ->delete(route('admin.users.destroy', $worker), $input)
            ->assertSessionHasErrors('confirm_deletion');

        $this->assertDatabaseHas('users', ['id' => $worker->id, 'is_active' => false]);
        Storage::disk('local')->assertExists($photoPath);
    }

    public static function invalidConfirmations(): array
    {
        return [
            'missing acknowledgement' => [[]],
            'acknowledgement declined' => [['confirm_deletion' => '0']],
            'malformed acknowledgement' => [['confirm_deletion' => ['1']]],
        ];
    }

    public function test_malformed_acknowledgement_redisplays_validation_feedback_without_a_server_error(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->inactive()->create();
        $response = $this->from(route('admin.users.index'))->actingAs($manager)
            ->delete(route('admin.users.destroy', $worker), ['confirm_deletion' => ['invalid input']]);

        $response->assertSessionHasErrors('confirm_deletion');
        $message = session('errors')->first('confirm_deletion');
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->followRedirects($response)->assertOk()->assertSee($message);
        $this->assertDatabaseHas('users', ['id' => $worker->id, 'is_active' => false]);
    }

    public function test_the_deletion_form_requires_no_typed_username_or_password(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->inactive()->create();
        $response = $this->actingAs($manager)->get(route('admin.users.index'))->assertOk();
        $document = new DOMDocument;
        $previousErrors = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
        $xpath = new DOMXPath($document);
        $forms = $xpath->query('//form[@action="'.route('admin.users.destroy', $worker).'"]');

        // Scope assertions to deletion: account creation elsewhere on the same
        // dashboard still correctly asks for a new username and password.
        $this->assertCount(1, $forms);
        $form = $forms->item(0);
        $this->assertSame(1, $xpath->query('.//input[@type="checkbox" and @name="confirm_deletion"]', $form)->length);
        $this->assertSame(1, $xpath->query('.//input[@type="hidden" and @name="_token"]', $form)->length);
        $this->assertSame(1, $xpath->query('.//input[@type="hidden" and @name="_method" and @value="DELETE"]', $form)->length);
        $this->assertSame(0, $xpath->query('.//input[not(@type="hidden") and not(@type="checkbox")]', $form)->length);
        $this->assertSame(0, $xpath->query('.//input[contains(@name,"username") or contains(@name,"password")]', $form)->length);
    }

    #[DataProvider('activeTargetRoles')]
    public function test_active_accounts_cannot_be_permanently_deleted_through_a_guessed_endpoint(string $role): void
    {
        $manager = User::factory()->manager()->create();
        $target = User::factory()->create(['role' => $role]);

        $this->actingAs($manager)->delete(route('admin.users.destroy', $target), $this->confirmation())
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => true]);
        $this->assertDatabaseCount('users', 2);
    }

    public static function activeTargetRoles(): array
    {
        return ['active worker' => ['worker'], 'active manager' => ['manager']];
    }

    public function test_the_last_manager_cannot_delete_their_own_account(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->delete(route('admin.users.destroy', $manager), $this->confirmation())
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('users', ['id' => $manager->id, 'is_active' => true]);
        $this->assertAuthenticatedAs($manager);
    }

    public function test_guests_and_workers_cannot_permanently_delete_accounts(): void
    {
        $target = User::factory()->inactive()->create();

        $this->delete(route('admin.users.destroy', $target), $this->confirmation())
            ->assertRedirect(route('login'));

        $worker = User::factory()->create();
        $this->actingAs($worker)->delete(route('admin.users.destroy', $target), $this->confirmation())
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    #[DataProvider('staleManagerChanges')]
    public function test_the_service_rechecks_a_stale_managers_current_authority(array $changes): void
    {
        $manager = User::factory()->manager()->create();
        $target = User::factory()->inactive()->create();
        User::query()->whereKey($manager->id)->update($changes);
        $denied = false;

        try {
            app(AccountDeletionService::class)->delete($manager, $target);
        } catch (AuthorizationException) {
            $denied = true;
        }

        $this->assertTrue($denied);
        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public static function staleManagerChanges(): array
    {
        return ['now inactive' => [['is_active' => false]], 'now a worker' => [['role' => 'worker']]];
    }

    public function test_the_service_rechecks_target_activity_instead_of_trusting_the_bound_model(): void
    {
        $manager = User::factory()->manager()->create();
        $target = User::factory()->inactive()->create();
        User::query()->whereKey($target->id)->update(['is_active' => true]);
        $rejected = false;

        try {
            app(AccountDeletionService::class)->delete($manager, $target);
        } catch (ValidationException) {
            $rejected = true;
        }

        $this->assertTrue($rejected);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => true]);
    }

    public function test_a_deleted_username_can_be_reused_without_inheriting_old_history(): void
    {
        $manager = User::factory()->manager()->create();
        $target = User::factory()->inactive()->create(['username' => 'reusable.worker']);
        $this->createShiftRecord($target, '2026-10-20');
        $this->createReceipt($target, '2026-10-01', true);

        app(AccountDeletionService::class)->delete($manager, $target);
        $replacement = app(AccountManagementService::class)->create($manager, [
            'username' => 'reusable.worker', 'first_name' => 'New', 'last_name' => 'Worker',
            'gender' => 'other', 'birth_date' => null, 'role' => 'worker', 'password' => 'New-test-password-93',
        ]);

        $this->assertNotSame($target->id, $replacement->id);
        $this->assertTrue($replacement->is_active);
        $this->assertDatabaseCount('shifts', 0);
        $this->assertDatabaseCount('monthly_receipts', 0);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_rolling_back_deletion_restores_the_account_and_keeps_its_avatar(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->inactive()->create();
        $photoPath = $this->attachAvatar($worker);
        $shift = $this->createShiftRecord($worker, '2026-10-20');
        $this->insertSession('rollback-worker-session', $worker);

        DB::beginTransaction();

        try {
            app(AccountDeletionService::class)->delete($manager, $worker);
            $this->assertDatabaseMissing('users', ['id' => $worker->id]);
            // The outer transaction still owns the commit, so unlinking now
            // would leave a restored account pointing at a missing image.
            Storage::disk('local')->assertExists($photoPath);
        } finally {
            DB::rollBack();
        }

        $this->assertDatabaseHas('users', ['id' => $worker->id, 'photo_path' => $photoPath]);
        $this->assertDatabaseHas('shifts', ['id' => $shift->id, 'worker_id' => $worker->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'rollback-worker-session']);
        Storage::disk('local')->assertExists($photoPath);

        // A later, unrelated commit must not run the discarded cleanup.
        DB::transaction(fn () => User::query()->whereKey($manager->id)->update(['first_name' => 'Updated']));
        Storage::disk('local')->assertExists($photoPath);
    }

    public function test_deletion_does_not_unlink_an_arbitrary_stored_photo_path(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->inactive()->create();
        Storage::disk('local')->put('private-document.txt', 'Keep this unrelated file.');
        $worker->forceFill(['photo_path' => 'private-document.txt'])->save();

        app(AccountDeletionService::class)->delete($manager, $worker);

        $this->assertDatabaseMissing('users', ['id' => $worker->id]);
        Storage::disk('local')->assertExists('private-document.txt');
    }

    #[DataProvider('lateSessionWriteStates')]
    public function test_a_late_authenticated_session_write_cannot_restore_a_deleted_account_or_session(bool $alreadyRead): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->inactive()->create();
        $this->insertSession('late-worker-session', $worker);
        $this->actingAs($worker);
        $handler = new DatabaseSessionHandler(DB::connection(), 'sessions', 43200, $this->app);

        if ($alreadyRead) {
            $handler->read('late-worker-session');
        }

        app(AccountDeletionService::class)->delete($manager, $worker);
        // The real Laravel handler handles an insert rejected by the user FK;
        // an already-read session instead updates zero rows after deletion.
        $this->assertTrue($handler->write('late-worker-session', serialize(['stale' => true])));

        $this->assertDatabaseMissing('users', ['id' => $worker->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'late-worker-session']);
    }

    public static function lateSessionWriteStates(): array
    {
        return ['request already read the session' => [true], 'request attempts a fresh insert' => [false]];
    }

    /** The route identifies the account; deletion needs acknowledgement only. */
    private function confirmation(): array
    {
        return ['confirm_deletion' => '1'];
    }

    private function createReceipt(User $worker, string $month, bool $hasReceipts): MonthlyReceipt
    {
        return MonthlyReceipt::create(['worker_id' => $worker->id, 'month' => $month, 'has_receipts' => $hasReceipts]);
    }

    private function insertSession(string $id, User $user): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $user->id, 'payload' => base64_encode(serialize([])), 'last_activity' => time(),
        ]);
    }

    private function attachAvatar(User $user): string
    {
        $path = 'avatars/'.str_repeat('a', 40).'.png';
        Storage::disk('local')->put($path, 'Private avatar fixture.');
        $user->forceFill(['photo_path' => $path])->save();

        return $path;
    }
}
