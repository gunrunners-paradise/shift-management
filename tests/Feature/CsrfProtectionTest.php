<?php

namespace Tests\Feature;

use App\Models\Desk;
use App\Models\User;
use App\Services\CalendarEventService;
use App\Services\DeskReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CsrfProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Laravel skips CSRF in normal feature tests. Override only that bypass,
        // leaving the real token comparison and session middleware in place.
        $this->app->bind(PreventRequestForgery::class, EnforcedRequestForgeryProtection::class);
    }

    public function test_login_rejects_a_missing_or_incorrect_csrf_token(): void
    {
        $this->withSession(['_token' => 'correct-session-token']);

        $this->post(route('login.store'), [
            'username' => 'any.worker',
            'password' => 'any-password',
        ])->assertStatus(419);
        $this->post(route('login.store'), [
            '_token' => 'incorrect-token',
            'username' => 'any.worker',
            'password' => 'any-password',
        ])->assertStatus(419);

        $this->assertGuest();
    }

    public function test_login_accepts_the_current_session_csrf_token(): void
    {
        $worker = User::factory()->create(['password' => 'Chosen-test-password-93']);
        $this->withSession(['_token' => 'correct-session-token']);

        $this->post(route('login.store'), [
            '_token' => 'correct-session-token',
            'username' => $worker->username,
            'password' => 'Chosen-test-password-93',
        ])->assertRedirect(route('worker.home'));

        $this->assertAuthenticatedAs($worker);
    }

    public function test_authenticated_account_actions_and_logout_reject_missing_csrf_tokens(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $this->actingAs($manager)->withSession(['_token' => 'correct-session-token']);

        $this->post(route('admin.users.store'), [])->assertStatus(419);
        $this->patch(route('admin.users.deactivate', $worker), [
            'confirm_deactivation' => '1',
        ])->assertStatus(419);
        $this->post(route('logout'))->assertStatus(419);

        $this->assertTrue($worker->fresh()->is_active);
        $this->assertAuthenticatedAs($manager);
    }

    public function test_permanent_account_deletion_requires_a_valid_csrf_token(): void
    {
        $manager = User::factory()->manager()->create();
        $target = User::factory()->inactive()->create();
        $input = ['confirm_deletion' => '1'];
        $this->actingAs($manager)->withSession(['_token' => 'correct-session-token']);

        $this->delete(route('admin.users.destroy', $target), $input)->assertStatus(419);
        $this->delete(route('admin.users.destroy', $target), array_merge($input, [
            '_token' => 'incorrect-token',
        ]))->assertStatus(419);

        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => false]);

        $this->delete(route('admin.users.destroy', $target), array_merge($input, [
            '_token' => 'correct-session-token',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_account_reactivation_requires_a_valid_csrf_token(): void
    {
        $manager = User::factory()->manager()->create();
        $target = User::factory()->inactive()->create();
        $this->actingAs($manager)->withSession(['_token' => 'correct-session-token']);

        $this->patch(route('admin.users.reactivate', $target))->assertStatus(419);
        $this->patch(route('admin.users.reactivate', $target), ['_token' => 'incorrect-token'])->assertStatus(419);

        $this->assertFalse($target->fresh()->is_active);

        $this->patch(route('admin.users.reactivate', $target), ['_token' => 'correct-session-token'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertTrue($target->fresh()->is_active);
        $this->assertSame(1, $target->fresh()->session_version);
    }

    public function test_shift_selection_and_cancellation_require_a_valid_csrf_token(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Jerusalem'));
        $worker = User::factory()->create();
        $this->actingAs($worker)->withSession(['_token' => 'correct-session-token']);

        $this->post(route('worker.shifts.store'), [
            'shift_date' => '2026-10-20',
            'period' => 'full',
        ])->assertStatus(419);
        $this->delete(route('worker.shifts.destroy', ['date' => '2026-10-20']))->assertStatus(419);
        $this->assertDatabaseCount('shifts', 0);

        $this->post(route('worker.shifts.store'), [
            '_token' => 'correct-session-token',
            'shift_date' => '2026-10-20',
            'period' => 'full',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('shifts', ['worker_id' => $worker->id, 'cancelled_at' => null]);
    }

    public function test_manager_block_and_unblock_endpoints_require_csrf_tokens(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Jerusalem'));
        $manager = User::factory()->manager()->create();
        $this->actingAs($manager)->withSession(['_token' => 'correct-session-token']);

        $this->post(route('admin.blocks.store'), [
            'shift_date' => '2026-10-21',
            'confirm_block' => '1',
        ])->assertStatus(419);
        $this->delete(route('admin.blocks.destroy', ['date' => '2026-10-21']), [
            'confirm_unblock' => '1',
        ])->assertStatus(419);

        $this->assertDatabaseCount('blocked_dates', 0);
    }

    public function test_profile_details_and_password_actions_reject_missing_csrf_tokens(): void
    {
        $user = User::factory()->create(['password' => 'Current-test-password-93']);
        $originalHash = $user->password;
        $originalName = $user->first_name;
        $this->actingAs($user)->withSession(['_token' => 'correct-session-token']);

        $this->patch(route('profile.update'), [
            'first_name' => 'Updated',
            'last_name' => 'Worker',
            'gender' => 'other',
        ])->assertStatus(419);
        $this->put(route('profile.password.update'), [
            'current_password' => 'Current-test-password-93',
            'password' => 'New-test-password-84',
            'password_confirmation' => 'New-test-password-84',
        ])->assertStatus(419);

        $this->assertSame($originalHash, $user->fresh()->password);
        $this->assertSame($originalName, $user->fresh()->first_name);
    }

    public function test_event_management_actions_require_csrf_tokens(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Jerusalem'));
        $manager = User::factory()->manager()->create();
        $attributes = ['title' => 'מפגש צוות', 'event_date' => '2026-10-20', 'kind' => 'event', 'description' => null];
        $event = app(CalendarEventService::class)->create($manager, $attributes);
        $this->actingAs($manager)->withSession(['_token' => 'correct-session-token']);

        $this->post(route('admin.events.store'), $attributes)->assertStatus(419);
        $this->patch(route('admin.events.update', $event), $attributes)->assertStatus(419);
        $this->delete(route('admin.events.destroy', $event), ['confirm_event_deletion' => '1'])->assertStatus(419);

        $this->assertDatabaseCount('calendar_events', 1);
        $this->assertDatabaseHas('calendar_events', ['id' => $event->id, 'title' => 'מפגש צוות']);
    }

    public function test_worker_receipts_require_a_csrf_token_before_saving(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Jerusalem'));
        $worker = User::factory()->create();
        $this->actingAs($worker)->withSession(['_token' => 'correct-session-token']);

        $this->put(route('worker.receipts.update'), ['month' => '2026-10', 'has_receipts' => '1'])->assertStatus(419);
        $this->assertDatabaseCount('monthly_receipts', 0);

        $this->put(route('worker.receipts.update'), [
            '_token' => 'correct-session-token', 'month' => '2026-10', 'has_receipts' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('monthly_receipts', ['worker_id' => $worker->id, 'has_receipts' => true]);
    }

    public function test_desk_booking_edit_and_cancellation_require_csrf_tokens(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00:00', 'Asia/Jerusalem'));
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();
        $reservation = app(DeskReservationService::class)->book($worker, $desk->id, '2026-10-21', '09:00', '10:00');
        $input = ['desk_id' => $desk->id, 'reservation_date' => '2026-10-21', 'start_time' => '12:00', 'end_time' => '13:00'];
        $this->actingAs($worker)->withSession(['_token' => 'correct-session-token']);

        $this->post(route('worker.desks.store'), $input)->assertStatus(419);
        $this->patch(route('worker.desks.update', $reservation), $input)->assertStatus(419);
        $this->delete(route('worker.desks.destroy', $reservation), ['confirm_cancellation' => '1'])->assertStatus(419);

        $this->assertNull($reservation->fresh()->cancelled_at);
        $this->assertDatabaseCount('desk_reservations', 1);
    }

    public function test_manager_desk_add_rename_and_deactivation_require_csrf_tokens(): void
    {
        $manager = User::factory()->manager()->create();
        $desk = Desk::query()->firstOrFail();
        $this->actingAs($manager)->withSession(['_token' => 'correct-session-token']);

        $this->post(route('admin.desks.store'), ['name' => 'עמדה חדשה'])->assertStatus(419);
        $this->patch(route('admin.desks.update', $desk), ['name' => 'שם חדש'])->assertStatus(419);
        $this->patch(route('admin.desks.deactivate', $desk), ['confirm_deactivation' => '1'])->assertStatus(419);

        $this->assertTrue($desk->fresh()->is_active);
        $this->assertSame('עמדה 1', $desk->fresh()->name);
        $this->assertDatabaseCount('desks', 9);
    }

    public function test_workday_csv_download_requires_a_valid_csrf_token(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00', 'Asia/Jerusalem'));
        $manager = User::factory()->manager()->create();
        $input = ['start_date' => '2026-10-18', 'end_date' => '2026-10-22'];
        $this->actingAs($manager)->withSession(['_token' => 'correct-session-token']);

        $this->post(route('admin.reports.workdays'), $input)->assertStatus(419);
        $this->post(route('admin.reports.workdays'), array_merge($input, ['_token' => 'incorrect-token']))
            ->assertStatus(419);

        $response = $this->post(route('admin.reports.workdays'), array_merge($input, [
            '_token' => 'correct-session-token',
        ]))->assertOk()->assertDownload();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->streamedContent());
    }
}

/** Keep the production middleware behavior while enabling it inside PHPUnit. */
class EnforcedRequestForgeryProtection extends PreventRequestForgery
{
    protected function runningUnitTests()
    {
        return false;
    }
}
