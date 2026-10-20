<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CalendarEventService;
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
}

/** Keep the production middleware behavior while enabling it inside PHPUnit. */
class EnforcedRequestForgeryProtection extends PreventRequestForgery
{
    protected function runningUnitTests()
    {
        return false;
    }
}
