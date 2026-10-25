<?php

namespace Tests\Feature;

use App\Models\Desk;
use App\Models\DeskReservation;
use App\Models\MonthlyReceipt;
use App\Models\Shift;
use App\Models\User;
use App\Services\AccountManagementService;
use App\Services\DeskReservationService;
use App\Services\MonthlyReceiptService;
use App\Services\ProfileService;
use App\Services\ShiftSchedulingService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Stage7WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Workflow-test-password-93';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-25 08:00:00', 'Asia/Jerusalem'));
    }

    public function test_native_forms_connect_account_creation_holidays_booking_closure_receipts_and_csv(): void
    {
        $firstManager = User::factory()->manager()->create(['password' => self::PASSWORD]);
        $desk = Desk::query()->firstOrFail();

        // Follow the real login and account forms: a newly created manager
        // must be able to create a worker without receiving worker privileges.
        $this->signIn($firstManager);
        $this->post(route('admin.users.store'), $this->accountAttributes('pilot.manager', 'manager'))
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.users.index'));
        $secondManager = User::where('username', 'pilot.manager')->firstOrFail();
        $this->signOut();
        $this->signIn($secondManager);
        $this->post(route('admin.users.store'), $this->accountAttributes('pilot.worker', 'worker'))
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.users.index'));
        $worker = User::where('username', 'pilot.worker')->firstOrFail();
        $this->get(route('worker.home'))->assertForbidden();
        $this->post(route('worker.desks.store'), $this->bookingAttributes($desk))->assertForbidden();
        $this->put(route('worker.receipts.update'), ['month' => '2026-10', 'has_receipts' => '1'])
            ->assertForbidden();
        $this->post(route('admin.events.store'), [
            'title' => 'חג ידני לבדיקה', 'event_date' => '2026-10-25',
            'kind' => 'holiday', 'description' => 'המשרד נשאר פתוח.',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->signOut();

        // A holiday is informational. The worker can independently book a
        // desk and a shift; missing receipt data initially means false.
        $this->signIn($worker);
        $this->get(route('admin.users.index'))->assertForbidden();
        $this->get(route('worker.home'))->assertOk()->assertViewHas('hasReceipts', false);
        $this->getJson(route('worker.today'))->assertOk()
            ->assertJsonPath('announcements.0.title', 'חג ידני לבדיקה');
        $this->post(route('worker.desks.store'), $this->bookingAttributes($desk))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('worker.shifts.store'), ['shift_date' => '2026-10-25', 'period' => 'full'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->put(route('worker.receipts.update'), ['month' => '2026-10', 'has_receipts' => '1'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $originalShift = Shift::active()->where('worker_id', $worker->id)->firstOrFail();
        $originalReservation = DeskReservation::active()->where('worker_id', $worker->id)->firstOrFail();
        $this->assertDatabaseCount('blocked_dates', 0);
        $this->signOut();

        // The same manager now closes both kinds of booking. CSV reflects
        // cancellation immediately while preserving the independent receipt.
        $this->signIn($secondManager);
        $this->assertSame([$worker->fullName(), '1', '25/10/2026', 'יש'], $this->downloadWorkerRow());
        $this->post(route('admin.blocks.store'), [
            'shift_date' => '2026-10-25', 'reason' => 'סגירה לבדיקה', 'confirm_block' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('date_blocked', $originalShift->fresh()->cancellation_reason);
        $this->assertSame('date_blocked', $originalReservation->fresh()->cancellation_reason);
        $this->assertSame([$worker->fullName(), '0', '', 'יש'], $this->downloadWorkerRow());
        $this->delete(route('admin.blocks.destroy', '2026-10-25'), ['confirm_unblock' => '1'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(0, Shift::active()->count());
        $this->assertSame(0, DeskReservation::active()->count());
        $this->signOut();

        // Reopening permits fresh bookings without resurrecting history.
        $this->signIn($worker);
        $this->post(route('worker.shifts.store'), ['shift_date' => '2026-10-25', 'period' => '10_14'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('worker.desks.store'), $this->bookingAttributes($desk))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, Shift::active()->count());
        $this->assertSame(1, DeskReservation::active()->count());
        $this->assertDatabaseCount('shifts', 2);
        $this->assertDatabaseCount('desk_reservations', 2);
        $this->assertNotNull($originalShift->fresh()->cancelled_at);
        $this->assertNotNull($originalReservation->fresh()->cancelled_at);
    }

    public function test_deactivation_reactivation_and_explicit_deletion_preserve_then_remove_only_owned_history(): void
    {
        $manager = User::factory()->manager()->create(['password' => self::PASSWORD]);
        $worker = User::factory()->create(['password' => self::PASSWORD]);
        $otherWorker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();

        $this->signIn($worker);
        $this->post(route('worker.shifts.store'), ['shift_date' => '2026-10-25', 'period' => 'full'])
            ->assertSessionHasNoErrors();
        $this->post(route('worker.desks.store'), $this->bookingAttributes($desk))
            ->assertSessionHasNoErrors();
        $this->put(route('worker.receipts.update'), ['month' => '2026-10', 'has_receipts' => '1'])
            ->assertSessionHasNoErrors();
        $originalPasswordHash = $worker->password;
        $this->signOut();

        // Both reservations are in progress when the manager deactivates the
        // worker. Reactivation restores access, not those canceled bookings.
        $this->travelTo(CarbonImmutable::parse('2026-10-25 09:30:00', 'Asia/Jerusalem'));
        $this->signIn($manager);
        $this->patch(route('admin.users.deactivate', $worker), ['confirm_deactivation' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame('worker_deactivated', Shift::firstOrFail()->cancellation_reason);
        $this->assertSame('worker_deactivated', DeskReservation::firstOrFail()->cancellation_reason);
        $this->assertDatabaseHas('monthly_receipts', ['worker_id' => $worker->id, 'has_receipts' => true]);
        $this->patch(route('admin.users.reactivate', $worker))->assertSessionHasNoErrors();
        $worker->refresh();
        $this->assertSame($originalPasswordHash, $worker->password);
        $this->assertSame(1, $worker->session_version);
        $this->assertSame(0, Shift::active()->count());
        $this->assertSame(0, DeskReservation::active()->count());
        $this->signOut();

        $this->signIn($worker);
        $this->get(route('worker.home'))->assertOk()->assertViewHas('hasReceipts', true);
        $this->post(route('worker.shifts.store'), ['shift_date' => '2026-10-26', 'period' => '09_13'])
            ->assertSessionHasNoErrors();
        $this->post(route('worker.desks.store'), $this->bookingAttributes($desk, '2026-10-26'))
            ->assertSessionHasNoErrors();
        $this->signOut();

        // Deletion is a separate explicit action after deactivation. It removes
        // owned history while leaving shared desks and date lock keys intact.
        $this->signIn($manager);
        $this->patch(route('admin.users.deactivate', $worker), ['confirm_deactivation' => '1'])
            ->assertSessionHasNoErrors();
        $this->delete(route('admin.users.destroy', $worker), ['confirm_deletion' => '1'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseMissing('users', ['id' => $worker->id]);
        $this->assertDatabaseMissing('shifts', ['worker_id' => $worker->id]);
        $this->assertDatabaseMissing('desk_reservations', ['worker_id' => $worker->id]);
        $this->assertDatabaseMissing('monthly_receipts', ['worker_id' => $worker->id]);
        $this->assertDatabaseHas('users', ['id' => $manager->id, 'is_active' => true]);
        $this->assertDatabaseHas('users', ['id' => $otherWorker->id, 'is_active' => true]);
        $this->assertDatabaseHas('desks', ['id' => $desk->id]);
        $this->assertDatabaseHas('calendar_dates', ['date' => '2026-10-25']);
        $this->assertDatabaseHas('calendar_dates', ['date' => '2026-10-26']);
        $this->assertSame([$otherWorker->fullName(), '0', '', 'אין'], $this->downloadWorkerRow());
    }

    #[DataProvider('mutationsRequiringCurrentLogin')]
    public function test_an_in_flight_request_from_before_reactivation_cannot_write_using_its_old_login(string $operation): void
    {
        $manager = User::factory()->manager()->create();
        $actor = User::factory()->create([
            'role' => $operation === 'manager' ? 'manager' : 'worker',
            'password' => self::PASSWORD,
        ])->fresh();
        $desk = Desk::query()->firstOrFail();
        $accounts = app(AccountManagementService::class);
        $accounts->deactivate($manager, $actor);
        $accounts->reactivate($manager, $actor);
        $before = $actor->fresh()->getRawOriginal();

        // Keep the actor object that middleware admitted before the account
        // lock was acquired. This deterministically reproduces the stale
        // version seen by a request that resumes after reactivation.
        $denied = false;

        try {
            $this->performMutation($operation, $actor, $desk);
        } catch (AuthorizationException) {
            $denied = true;
        }

        $this->assertTrue($denied, 'The locked authority check must reject the old login.');
        $this->assertSame($before, $actor->fresh()->getRawOriginal());
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('shifts', 0);
        $this->assertDatabaseCount('desk_reservations', 0);
        $this->assertDatabaseCount('monthly_receipts', 0);

        // A fresh login resolves version 1 and may perform the same valid action.
        $currentActor = $actor->fresh();
        $this->performMutation($operation, $currentActor, $desk);

        switch ($operation) {
            case 'manager':
                $this->assertDatabaseHas('users', ['username' => 'new.worker', 'role' => 'worker']);
                break;
            case 'shift':
                $this->assertSame(1, Shift::active()->where('worker_id', $actor->id)->count());
                break;
            case 'desk':
                $this->assertSame(1, DeskReservation::active()->where('worker_id', $actor->id)->count());
                break;
            case 'receipt':
                $this->assertTrue(MonthlyReceipt::where('worker_id', $actor->id)->firstOrFail()->has_receipts);
                break;
            case 'profile':
                $this->assertSame('Updated', $actor->fresh()->first_name);
                break;
            case 'password':
                $this->assertTrue(Hash::check('New-workflow-password-94', $actor->fresh()->password));
                break;
        }
    }

    public static function mutationsRequiringCurrentLogin(): array
    {
        return [
            'manager administration' => ['manager'],
            'shift selection' => ['shift'],
            'desk booking' => ['desk'],
            'receipt update' => ['receipt'],
            'profile update' => ['profile'],
            'password change' => ['password'],
        ];
    }

    /** Exercise public service entry points after their normal HTTP validation. */
    private function performMutation(string $operation, User $actor, Desk $desk): void
    {
        switch ($operation) {
            case 'manager':
                app(AccountManagementService::class)->create($actor, $this->accountAttributes('new.worker', 'worker'));
                break;
            case 'shift':
                app(ShiftSchedulingService::class)->select($actor, '2026-10-25', 'full');
                break;
            case 'desk':
                app(DeskReservationService::class)->book($actor, $desk->id, '2026-10-25', '09:00', '10:00');
                break;
            case 'receipt':
                app(MonthlyReceiptService::class)->set($actor, '2026-10', true);
                break;
            case 'profile':
                app(ProfileService::class)->update($actor, [
                    'first_name' => 'Updated', 'last_name' => $actor->last_name, 'gender' => $actor->gender,
                ]);
                break;
            case 'password':
                app(ProfileService::class)->changePassword($actor, self::PASSWORD, 'New-workflow-password-94', 'workflow-current-session');
                break;
        }
    }

    private function signIn(User $user): void
    {
        $this->post(route('login.store'), ['username' => $user->username, 'password' => self::PASSWORD])
            ->assertSessionHasNoErrors()->assertRedirect(route($user->homeRoute()));
        $this->withCookie(config('session.cookie'), session()->getId());
    }

    private function signOut(): void
    {
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->withCookie(config('session.cookie'), session()->getId());
    }

    private function accountAttributes(string $username, string $role): array
    {
        return [
            'username' => $username, 'first_name' => 'Pilot', 'last_name' => $role,
            'gender' => 'other', 'role' => $role, 'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ];
    }

    private function bookingAttributes(Desk $desk, string $date = '2026-10-25'): array
    {
        return [
            'desk_id' => $desk->id, 'reservation_date' => $date,
            'start_time' => '09:00', 'end_time' => '10:00',
        ];
    }

    /** Download and parse the sole worker row through the real POST endpoint. */
    private function downloadWorkerRow(): array
    {
        $response = $this->post(route('admin.reports.workdays'), [
            'start_date' => '2026-10-25', 'end_date' => '2026-10-25', 'receipt_month' => '2026-10',
        ])->assertOk()->assertDownload();
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $stream = fopen('php://temp', 'w+');

        try {
            fwrite($stream, substr($csv, 3));
            rewind($stream);
            $this->assertSame(['שם העובד', 'מספר ימי עבודה', 'תאריכי עבודה', 'קבלות'], fgetcsv($stream, null, ',', '"', ''));
            $row = fgetcsv($stream, null, ',', '"', '');
            $this->assertIsArray($row);
            $this->assertCount(4, $row);
            $this->assertFalse(fgetcsv($stream, null, ',', '"', ''), 'Managers and deleted accounts must not appear as extra rows.');

            return $row;
        } finally {
            fclose($stream);
        }
    }
}
