<?php

namespace Tests\Feature;

use App\Models\Desk;
use App\Models\MonthlyReceipt;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\AccountManagementService;
use App\Services\DeskReservationService;
use App\Services\ShiftSchedulingService;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesDeskReservations;
use Tests\Concerns\CreatesShiftRecords;
use Tests\TestCase;

class AccountReactivationTest extends TestCase
{
    use CreatesDeskReservations, CreatesShiftRecords, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00', 'Asia/Jerusalem'));
        Storage::fake('local');
    }

    #[DataProvider('reactivatedRoleDestinations')]
    public function test_a_manager_reactivates_an_account_without_new_credentials_and_preserves_its_profile(string $role, string $destination): void
    {
        $manager = User::factory()->manager()->create();
        $password = 'Existing-test-password-93';
        $photoPath = 'avatars/'.str_repeat('a', 40).'.png';
        Storage::disk('local')->put($photoPath, 'Existing private image.');
        $target = User::factory()->inactive()->create([
            'role' => $role, 'password' => $password, 'first_name' => 'Existing',
            'last_name' => 'Account', 'gender' => 'female', 'birth_date' => '1990-03-15',
            'photo_path' => $photoPath, 'remember_token' => str_repeat('r', 60),
        ]);
        // Compare stored DATE values, rather than Eloquent's creation-time
        // input representation, which can still include a midnight timestamp.
        $target->refresh();
        $originalFields = $target->getRawOriginal();

        $this->actingAs($manager)->patch(route('admin.users.reactivate', $target))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $target->refresh();
        $this->assertTrue($target->is_active);
        $this->assertNull($target->remember_token);
        $this->assertSame(1, $target->session_version);

        foreach (['id', 'username', 'first_name', 'last_name', 'gender', 'birth_date', 'photo_path', 'role', 'password', 'created_at'] as $field) {
            $this->assertSame($originalFields[$field], $target->getRawOriginal($field), $field.' must survive reactivation.');
        }

        $this->assertTrue(Hash::check($password, $target->password));
        Storage::disk('local')->assertExists($photoPath);

        // Reactivation preserves the chosen password; the owner signs in again
        // through the normal role-specific login rather than inheriting a session.
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->post(route('login.store'), ['username' => $target->username, 'password' => $password])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route($destination));
        $this->assertAuthenticatedAs($target);
        $this->assertSame($target->session_version, session('auth_session_version'));
    }

    public static function reactivatedRoleDestinations(): array
    {
        return ['worker account' => ['worker', 'worker.home'], 'manager account' => ['manager', 'admin.users.index']];
    }

    public function test_reactivation_removes_old_account_sessions_and_retains_other_users_sessions(): void
    {
        $manager = User::factory()->manager()->create();
        $target = User::factory()->inactive()->create();
        $otherWorker = User::factory()->create();
        $this->insertSession('inactive-account-first-session', $target);
        $this->insertSession('inactive-account-second-session', $target);
        $this->insertSession('retained-manager-session', $manager);
        $this->insertSession('retained-worker-session', $otherWorker);

        $this->actingAs($manager)->patch(route('admin.users.reactivate', $target))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'retained-manager-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'retained-worker-session']);
        $this->assertTrue($target->fresh()->is_active);
        $this->assertAuthenticatedAs($manager);
    }

    public function test_reactivation_of_an_already_active_account_is_harmless_and_does_not_revoke_sessions(): void
    {
        $manager = User::factory()->manager()->create();
        $target = User::factory()->create(['remember_token' => str_repeat('r', 60)]);
        $target->refresh();
        $originalFields = $target->getRawOriginal();
        $this->insertSession('active-account-session', $target);

        $this->actingAs($manager)->patch(route('admin.users.reactivate', $target))
            ->assertSessionHasNoErrors();
        app(AccountManagementService::class)->reactivate($manager, $target);

        $this->assertSame($originalFields, $target->fresh()->getRawOriginal());
        $this->assertDatabaseHas('sessions', ['id' => 'active-account-session', 'user_id' => $target->id]);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_a_late_old_session_write_cannot_restore_access_after_reactivation(): void
    {
        // Use the real encrypted database session payload and cookie path. The
        // ordinary feature-test array driver cannot reproduce a late DB write.
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $manager = User::factory()->manager()->create();
        $password = 'Existing-test-password-93';
        $worker = User::factory()->create(['password' => $password]);

        $this->post(route('login.store'), ['username' => $worker->username, 'password' => $password])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('worker.home'));
        $oldSessionId = session()->getId();
        $oldPayload = DB::table('sessions')->where('id', $oldSessionId)->value('payload');
        $this->assertIsString($oldPayload);
        $this->assertSame(0, session('auth_session_version'));
        app(AccountManagementService::class)->deactivate($manager, $worker);
        app(AccountManagementService::class)->reactivate($manager, $worker);
        $this->assertDatabaseMissing('sessions', ['user_id' => $worker->id]);

        // An old request can finish after reactivation. Its user FK is valid
        // again, so row deletion alone cannot prevent this write from succeeding.
        $handler = new DatabaseSessionHandler(DB::connection(), 'sessions', 43200, $this->app);
        $this->assertTrue($handler->write($oldSessionId, base64_decode($oldPayload, true)));
        $this->assertDatabaseHas('sessions', ['id' => $oldSessionId, 'user_id' => $worker->id]);
        $this->app['auth']->forgetGuards();

        $this->withCookie(config('session.cookie'), $oldSessionId)
            ->get(route('worker.home'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertTrue($worker->fresh()->is_active);
        $this->assertSame(1, $worker->fresh()->session_version);

        $this->withCookie(config('session.cookie'), session()->getId())
            ->post(route('login.store'), ['username' => $worker->username, 'password' => $password])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('worker.home'));
        $this->assertAuthenticatedAs($worker);
        $this->assertSame(1, session('auth_session_version'));
        $this->withCookie(config('session.cookie'), session()->getId())
            ->get(route('worker.home'))->assertOk();
    }

    public function test_reactivation_preserves_canceled_history_and_allows_fresh_shift_and_desk_bookings(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();
        $completedShift = $this->createShiftRecord($worker, '2026-10-20');
        $runningShift = $this->createShiftRecord($worker, '2026-10-22', 'full');
        $futureShift = $this->createShiftRecord($worker, '2026-10-25', '10_14');
        $completedReservation = $this->createDeskReservation($worker, $desk, '2026-10-20');
        $runningReservation = $this->createDeskReservation($worker, $desk, '2026-10-22', '09:00', '16:30');
        $futureReservation = $this->createDeskReservation($worker, $desk, '2026-10-25');
        MonthlyReceipt::create(['worker_id' => $worker->id, 'month' => '2026-10-01', 'has_receipts' => true]);
        app(AccountManagementService::class)->deactivate($manager, $worker);
        $shiftHistory = DB::table('shifts')->where('worker_id', $worker->id)->orderBy('id')->get()->toArray();
        $deskHistory = DB::table('desk_reservations')->where('worker_id', $worker->id)->orderBy('id')->get()->toArray();

        $this->actingAs($manager)->patch(route('admin.users.reactivate', $worker))
            ->assertSessionHasNoErrors();

        $this->assertTrue($worker->fresh()->is_active);
        $this->assertEquals($shiftHistory, DB::table('shifts')->where('worker_id', $worker->id)->orderBy('id')->get()->toArray());
        $this->assertEquals($deskHistory, DB::table('desk_reservations')->where('worker_id', $worker->id)->orderBy('id')->get()->toArray());
        $this->assertNull($completedShift->fresh()->cancelled_at);
        $this->assertNull($completedReservation->fresh()->cancelled_at);

        foreach ([$runningShift, $futureShift, $runningReservation, $futureReservation] as $canceledRecord) {
            $this->assertNotNull($canceledRecord->fresh()->cancelled_at);
            $this->assertSame('worker_deactivated', $canceledRecord->fresh()->cancellation_reason);
        }

        $this->assertDatabaseHas('monthly_receipts', ['worker_id' => $worker->id, 'month' => '2026-10-01', 'has_receipts' => true]);
        $freshShift = app(ShiftSchedulingService::class)->select($worker, '2026-10-25', '09_13');
        $freshReservation = app(DeskReservationService::class)->book($worker, $desk->id, '2026-10-25', '09:00', '10:00');

        $this->assertNotSame($futureShift->id, $freshShift->id);
        $this->assertNotSame($futureReservation->id, $freshReservation->id);
        $this->assertNull($freshShift->cancelled_at);
        $this->assertNull($freshReservation->cancelled_at);
        $this->assertNotNull($futureShift->fresh()->cancelled_at);
        $this->assertNotNull($futureReservation->fresh()->cancelled_at);
    }

    public function test_reactivation_cannot_replace_the_targets_identity_password_or_role_with_submitted_fields(): void
    {
        $manager = User::factory()->manager()->create();
        $target = User::factory()->inactive()->create(['first_name' => 'Original']);
        $originalUsername = $target->username;
        $originalPassword = $target->password;

        $this->actingAs($manager)->patch(route('admin.users.reactivate', $target), [
            'username' => 'unapproved.username', 'password' => 'Unapproved-password-93',
            'role' => 'manager', 'first_name' => 'Unapproved', 'is_active' => false,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'id' => $target->id, 'username' => $originalUsername, 'password' => $originalPassword,
            'role' => 'worker', 'first_name' => 'Original', 'is_active' => true,
        ]);
    }

    public function test_a_guest_cannot_reactivate_an_account(): void
    {
        $target = User::factory()->inactive()->create();

        $this->patch(route('admin.users.reactivate', $target))->assertRedirect(route('login'));

        $this->assertFalse($target->fresh()->is_active);
    }

    public function test_a_worker_cannot_reactivate_an_account(): void
    {
        $worker = User::factory()->create();
        $target = User::factory()->inactive()->create();

        $this->actingAs($worker)->patch(route('admin.users.reactivate', $target))->assertForbidden();

        $this->assertFalse($target->fresh()->is_active);
    }

    public function test_an_already_authenticated_manager_who_was_deactivated_cannot_reactivate_accounts(): void
    {
        $manager = User::factory()->manager()->create();
        $target = User::factory()->inactive()->create();
        $this->actingAs($manager);
        User::query()->whereKey($manager->id)->update(['is_active' => false]);

        $this->patch(route('admin.users.reactivate', $target))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertFalse($target->fresh()->is_active);
    }

    #[DataProvider('staleManagerChanges')]
    public function test_the_service_rechecks_current_manager_authority_before_reactivation(string $change): void
    {
        $manager = User::factory()->manager()->create();
        $target = User::factory()->inactive()->create();

        if ($change === 'deleted') {
            User::query()->whereKey($manager->id)->delete();
        } else {
            User::query()->whereKey($manager->id)->update($change === 'inactive' ? ['is_active' => false] : ['role' => 'worker']);
        }

        $denied = false;

        try {
            app(AccountManagementService::class)->reactivate($manager, $target);
        } catch (AuthorizationException) {
            $denied = true;
        }

        $this->assertTrue($denied);
        $this->assertFalse($target->fresh()->is_active);
    }

    public static function staleManagerChanges(): array
    {
        return ['manager now inactive' => ['inactive'], 'manager now a worker' => ['worker'], 'manager now deleted' => ['deleted']];
    }

    public function test_a_permanently_deleted_account_cannot_be_reactivated(): void
    {
        $manager = User::factory()->manager()->create();
        $target = User::factory()->inactive()->create();
        app(AccountDeletionService::class)->delete($manager, $target);

        $this->actingAs($manager)->patch(route('admin.users.reactivate', $target))->assertNotFound();

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_only_inactive_accounts_show_a_native_reactivation_button_without_extra_credentials(): void
    {
        $manager = User::factory()->manager()->create();
        $activeWorker = User::factory()->create();
        $inactiveWorker = User::factory()->inactive()->create();
        $inactiveManager = User::factory()->manager()->inactive()->create();
        $response = $this->actingAs($manager)->get(route('admin.users.index'))->assertOk()->assertSee('הפעלת חשבון מחדש');
        $document = new DOMDocument;
        $previousErrors = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
        $xpath = new DOMXPath($document);

        foreach ([$manager, $activeWorker] as $activeAccount) {
            $this->assertSame(0, $xpath->query('//form[@action="'.route('admin.users.reactivate', $activeAccount).'"]')->length);
        }

        foreach ([$inactiveWorker, $inactiveManager] as $inactiveAccount) {
            $forms = $xpath->query('//form[@action="'.route('admin.users.reactivate', $inactiveAccount).'"]');
            $this->assertCount(1, $forms);
            $form = $forms->item(0);
            $this->assertSame(1, $xpath->query('.//input[@type="hidden" and @name="_token"]', $form)->length);
            $this->assertSame(1, $xpath->query('.//input[@type="hidden" and @name="_method" and @value="PATCH"]', $form)->length);
            $this->assertSame(0, $xpath->query('.//input[not(@type="hidden")]', $form)->length);
        }
    }

    private function insertSession(string $id, User $user): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $user->id, 'payload' => base64_encode(serialize([])), 'last_activity' => time(),
        ]);
    }
}
