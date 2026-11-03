<?php

namespace Tests\Feature;

use App\Models\PendingSignupRequest;
use App\Models\SignupSetting;
use App\Models\User;
use App\Services\AccountManagementService;
use App\Services\SignupService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SignupRequestTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Applicant-chosen-password-93';

    public function test_both_signup_pages_start_closed_and_direct_submissions_create_nothing(): void
    {
        foreach (['worker', 'manager'] as $role) {
            $this->get(route('signup.create', $role))
                ->assertForbidden()->assertSee('ההרשמה סגורה כעת');
            $this->post(route('signup.store', $role), $this->applicantAttributes())
                ->assertForbidden()->assertSee('ההרשמה סגורה כעת');
        }

        $this->assertDatabaseCount('pending_signup_requests', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_manager_controls_the_two_persistent_signup_switches_independently(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->patch(route('admin.signups.settings.update', 'worker'), ['is_enabled' => '1'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertTrue(SignupSetting::query()->findOrFail('worker')->is_enabled);
        $this->assertFalse(SignupSetting::query()->findOrFail('manager')->is_enabled);

        $this->patch(route('admin.signups.settings.update', 'manager'), ['is_enabled' => '1'])
            ->assertSessionHasNoErrors();
        $this->patch(route('admin.signups.settings.update', 'worker'), ['is_enabled' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertFalse(SignupSetting::query()->findOrFail('worker')->is_enabled);
        $this->assertTrue(SignupSetting::query()->findOrFail('manager')->is_enabled);
    }

    #[DataProvider('signupRoles')]
    public function test_each_public_signup_page_records_only_a_pending_request_with_its_fixed_role(string $role): void
    {
        $this->openSignup($role);
        $attributes = $this->applicantAttributes(['username' => '  NEW.APPLICANT  ']);

        $this->post(route('signup.store', $role), $attributes)
            ->assertSessionHasNoErrors()->assertRedirect(route('signup.submitted'));

        $pending = PendingSignupRequest::query()->sole();
        $this->assertSame('new.applicant', $pending->username);
        $this->assertSame($role, $pending->role);
        $this->assertSame('2000-02-29', $pending->birth_date->format('Y-m-d'));
        $this->assertTrue(Hash::check(self::PASSWORD, $pending->password));
        $this->assertArrayNotHasKey('password', $pending->toArray());
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();

        $this->get(route('signup.submitted'))->assertOk()
            ->assertSee('הבקשה נשלחה וממתינה לאישור')
            ->assertSee('צרו קשר עם המנהל/ת לאישור חשבון המשתמש שלכם')
            ->assertDontSee(self::PASSWORD);
    }

    public function test_pending_applicants_cannot_log_in_before_manager_approval(): void
    {
        $this->openSignup('worker');
        $this->post(route('signup.store', 'worker'), $this->applicantAttributes())->assertRedirect();

        $this->post(route('login.store'), ['username' => 'new.applicant', 'password' => self::PASSWORD])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
        $this->assertDatabaseCount('pending_signup_requests', 1);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_closing_a_page_rejects_an_already_open_form_and_preserves_existing_requests(): void
    {
        $this->openSignup('worker');
        $pending = $this->pendingRequest('already.pending');
        $this->get(route('signup.create', 'worker'))->assertOk();
        $manager = User::factory()->manager()->create();
        app(SignupService::class)->setEnabled($manager, 'worker', false);

        $this->post(route('signup.store', 'worker'), $this->applicantAttributes())
            ->assertForbidden()->assertSee('ההרשמה סגורה כעת');

        $this->assertDatabaseHas('pending_signup_requests', ['id' => $pending->id]);
        $this->assertDatabaseMissing('pending_signup_requests', ['username' => 'new.applicant']);
        $this->assertDatabaseCount('users', 1);
    }

    #[DataProvider('optionalBirthdays')]
    public function test_birthday_may_be_omitted_or_blank(array $birthdayAttributes): void
    {
        $this->openSignup('worker');
        $attributes = $this->applicantAttributes();
        unset($attributes['birth_date']);

        $this->post(route('signup.store', 'worker'), array_replace($attributes, $birthdayAttributes))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertNull(PendingSignupRequest::query()->sole()->birth_date);
    }

    #[DataProvider('invalidApplicantFields')]
    public function test_invalid_public_signup_input_is_rejected_without_creating_any_account(string $field, mixed $value, bool $matchConfirmation = true): void
    {
        $this->openSignup('worker');
        $attributes = $this->applicantAttributes([$field => $value]);

        if ($field === 'password' && $matchConfirmation) {
            $attributes['password_confirmation'] = $value;
        }

        $this->from(route('signup.create', 'worker'))->post(route('signup.store', 'worker'), $attributes)
            ->assertSessionHasErrors($field)->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation');

        $this->assertDatabaseCount('pending_signup_requests', 0);
        $this->assertDatabaseCount('users', 0);
    }

    #[DataProvider('injectedAuthorityFields')]
    public function test_applicants_cannot_submit_role_activation_or_other_account_authority(string $field, mixed $value): void
    {
        $this->openSignup('worker');

        $this->post(route('signup.store', 'worker'), $this->applicantAttributes([$field => $value]))
            ->assertSessionHasErrors($field);

        $this->assertDatabaseCount('pending_signup_requests', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_password_that_looks_like_a_hash_is_still_hashed_as_the_literal_chosen_password(): void
    {
        $this->openSignup('manager');
        $hashLookingPassword = Hash::make('Another-plaintext-password-73');

        $this->post(route('signup.store', 'manager'), $this->applicantAttributes([
            'password' => $hashLookingPassword, 'password_confirmation' => $hashLookingPassword,
        ]))->assertSessionHasNoErrors();

        $pending = PendingSignupRequest::query()->sole();
        $this->assertNotSame($hashLookingPassword, $pending->password);
        $this->assertTrue(Hash::check($hashLookingPassword, $pending->password));
        $this->assertFalse(Hash::check('Another-plaintext-password-73', $pending->password));
    }

    public function test_password_whitespace_and_exact_twelve_character_boundary_survive_submission_and_approval(): void
    {
        $this->openSignup('worker');
        $password = '  abcdefgh  ';
        $this->post(route('signup.store', 'worker'), $this->applicantAttributes([
            'password' => $password, 'password_confirmation' => $password,
        ]))->assertSessionHasNoErrors();
        $pending = PendingSignupRequest::query()->sole();
        $manager = User::factory()->manager()->create();
        $review = app(SignupService::class)->review($manager, 'approve');

        app(SignupService::class)->process($manager, $review['token']);

        $account = User::query()->where('username', 'new.applicant')->sole();
        $this->assertSame($pending->password, $account->password);
        $this->assertTrue(Hash::check($password, $account->password));
        $this->assertFalse(Hash::check(trim($password), $account->password));
    }

    #[DataProvider('existingAccountTypes')]
    public function test_existing_active_or_inactive_usernames_are_never_reused_by_signup(string $role, bool $isActive): void
    {
        $this->openSignup('worker');
        $existing = User::factory()->create(['username' => 'existing.account', 'role' => $role, 'is_active' => $isActive]);
        $original = $existing->fresh()->getRawOriginal();

        $this->post(route('signup.store', 'worker'), $this->applicantAttributes(['username' => ' EXISTING.ACCOUNT ']))
            ->assertSessionHasErrors('username');

        $this->assertSame($original, $existing->fresh()->getRawOriginal());
        $this->assertDatabaseCount('pending_signup_requests', 0);
    }

    #[DataProvider('signupRoles')]
    public function test_pending_usernames_are_unique_across_both_signup_pages(string $existingRole): void
    {
        $this->openSignup('worker');
        $this->openSignup('manager');
        $existing = $this->pendingRequest('already.pending', $existingRole);
        $original = $existing->fresh()->getRawOriginal();

        $this->post(route('signup.store', $existingRole === 'worker' ? 'manager' : 'worker'), $this->applicantAttributes([
            'username' => ' ALREADY.PENDING ', 'password' => 'Different-password-98',
            'password_confirmation' => 'Different-password-98',
        ]))->assertSessionHasErrors('username');

        $this->assertDatabaseCount('pending_signup_requests', 1);
        $this->assertSame($original, $existing->fresh()->getRawOriginal());
    }

    public function test_people_with_identical_full_names_can_apply_with_different_usernames(): void
    {
        $this->openSignup('worker');
        User::factory()->create(['first_name' => 'דנה', 'last_name' => 'כהן']);
        $this->pendingRequest('same.name');

        $this->post(route('signup.store', 'worker'), $this->applicantAttributes())
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseCount('pending_signup_requests', 2);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_public_forms_show_the_password_minimum_and_have_no_logo_or_signup_links(): void
    {
        foreach (['worker', 'manager'] as $role) {
            $this->openSignup($role);
            $response = $this->get(route('signup.create', $role))->assertOk()
                ->assertSee('הסיסמה חייבת להכיל לפחות 12 תווים')
                ->assertSee('name="username"', false)->assertSee('name="password_confirmation"', false)
                ->assertDontSee('enter_digital_studio.svg', false)
                ->assertDontSee('name="role"', false)->assertDontSee('name="is_active"', false);
            $this->assertNoSignupHyperlinks($response->getContent());
        }

        $this->assertNoSignupHyperlinks($this->get(route('login'))->assertOk()->getContent());
        $manager = User::factory()->manager()->create();
        $this->assertNoSignupHyperlinks($this->actingAs($manager)->get(route('admin.users.index'))->assertOk()->getContent());
    }

    public function test_unknown_signup_roles_have_no_public_page_or_mutation(): void
    {
        $this->get('/signup/administrator')->assertNotFound();
        $this->post('/signup/administrator', $this->applicantAttributes())->assertNotFound();
        $this->assertDatabaseCount('pending_signup_requests', 0);
    }

    public function test_signup_submission_and_manager_actions_require_csrf_tokens(): void
    {
        $this->openSignup('worker');
        $manager = User::factory()->manager()->create();
        $this->app->detectEnvironment(fn (): string => 'local');

        $this->post(route('signup.store', 'worker'), $this->applicantAttributes())->assertStatus(419);
        $this->actingAs($manager)->patch(route('admin.signups.settings.update', 'worker'), ['is_enabled' => '0'])->assertStatus(419);
        $this->post(route('admin.signups.review'), ['action' => 'approve', 'scope' => 'all'])->assertStatus(419);
        $this->post(route('admin.signups.process'), ['token' => 'untrusted'])->assertStatus(419);

        $this->assertDatabaseCount('pending_signup_requests', 0);
    }

    public function test_guests_and_workers_cannot_control_settings_review_or_process_requests(): void
    {
        $pending = $this->pendingRequest('not.authorized');
        $this->patch(route('admin.signups.settings.update', 'worker'), ['is_enabled' => '1'])->assertRedirect(route('login'));
        $this->post(route('admin.signups.review'), ['action' => 'approve', 'scope' => 'all'])->assertRedirect(route('login'));
        $this->post(route('admin.signups.process'), ['token' => 'untrusted'])->assertRedirect(route('login'));
        $worker = User::factory()->create();

        $this->actingAs($worker)->patch(route('admin.signups.settings.update', 'worker'), ['is_enabled' => '1'])->assertForbidden();
        $this->post(route('admin.signups.review'), ['action' => 'approve', 'scope' => 'single', 'request_id' => $pending->id])->assertForbidden();
        $this->post(route('admin.signups.process'), ['token' => 'untrusted'])->assertForbidden();

        $this->assertDatabaseHas('pending_signup_requests', ['id' => $pending->id]);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_submission_limits_are_shared_between_worker_and_manager_pages(): void
    {
        $this->openSignup('worker');
        $this->openSignup('manager');

        // Invalid attempts still consume the budget; switching roles must not
        // double it. A shared office IP may make twenty attempts per minute.
        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $role = $attempt % 2 === 0 ? 'manager' : 'worker';
            $this->postJson(route('signup.store', $role), [])->assertUnprocessable();
        }
        $this->postJson(route('signup.store', 'worker'), $this->applicantAttributes())
            ->assertStatus(429)->assertHeader('Retry-After');

        $this->travel(61)->seconds();
        $this->post(route('signup.store', 'manager'), $this->applicantAttributes())
            ->assertSessionHasNoErrors()->assertRedirect(route('signup.submitted'));
        $this->assertDatabaseCount('pending_signup_requests', 1);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_confirmation_page_is_not_available_before_a_successful_submission(): void
    {
        $this->get(route('signup.submitted'))->assertNotFound();
        $this->assertGuest();
    }

    #[DataProvider('invalidManagerReviewFields')]
    public function test_review_rejects_invalid_actions_scope_and_client_chosen_roles(array $attributes): void
    {
        $manager = User::factory()->manager()->create();
        $pending = $this->pendingRequest('unchanged.review');

        $this->actingAs($manager)->post(route('admin.signups.review'), $attributes)
            ->assertSessionHasErrors([], null, 'signupManagement');

        $this->assertDatabaseHas('pending_signup_requests', ['id' => $pending->id, 'role' => 'worker']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_combined_pending_list_has_ten_rows_per_page_and_bulk_controls_above_the_list(): void
    {
        $manager = User::factory()->manager()->create();
        for ($number = 1; $number <= 11; $number++) {
            $this->pendingRequest(sprintf('pending.%02d', $number), $number % 2 === 0 ? 'manager' : 'worker');
        }

        $response = $this->actingAs($manager)->get(route('admin.users.index'))->assertOk();
        $pending = $response->viewData('signupRequests');
        $this->assertSame(10, $pending->count());
        $this->assertSame(11, $pending->total());
        $this->assertSame(10, $pending->perPage());
        $response->assertSeeInOrder(['אישור כל הבקשות', 'מחיקת כל הבקשות', 'pending.01']);
        $this->assertNoSignupHyperlinks($response->getContent());
        $this->assertDatabaseCount('users', 1);
    }

    public function test_one_confirmation_approves_both_roles_across_pages_and_excludes_later_arrivals(): void
    {
        $manager = User::factory()->manager()->create();
        $originalManager = $manager->fresh()->getRawOriginal();
        $pendingHashes = [];
        for ($number = 1; $number <= 12; $number++) {
            $pending = $this->pendingRequest('batch.'.$number, $number % 2 === 0 ? 'manager' : 'worker');
            $pendingHashes[$pending->username] = $pending->password;
        }

        $response = $this->actingAs($manager)->post(route('admin.signups.review'), ['action' => 'approve', 'scope' => 'all'])
            ->assertOk()->assertSee('מנהל')->assertDontSee(self::PASSWORD);
        $review = $response->viewData('review');
        $this->assertSame(6, $review['worker_count']);
        $this->assertSame(6, $review['manager_count']);
        $this->assertSame(12, $review['total']);
        $token = $review['token'];
        $lateRequest = $this->pendingRequest('after.confirmation', 'manager');
        $this->post(route('admin.signups.process'), ['token' => $token])
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.users.index').'#signup-requests');

        $this->assertDatabaseCount('users', 13);
        $this->assertSame(6, User::query()->where('role', 'worker')->count());
        $this->assertSame(7, User::query()->where('role', 'manager')->count());
        foreach ($pendingHashes as $username => $hash) {
            $account = User::query()->where('username', $username)->sole();
            $this->assertTrue($account->is_active);
            $this->assertSame($hash, $account->password);
            $this->assertNull($account->photo_path);
        }
        $this->assertSame($originalManager, $manager->fresh()->getRawOriginal());
        $this->assertDatabaseCount('pending_signup_requests', 1);
        $this->assertDatabaseHas('pending_signup_requests', ['id' => $lateRequest->id]);
        $this->assertAuthenticatedAs($manager);
    }

    public function test_individual_approval_uses_the_original_role_and_only_the_selected_request(): void
    {
        $manager = User::factory()->manager()->create();
        $chosen = $this->pendingRequest('chosen.manager', 'manager');
        $other = $this->pendingRequest('untouched.worker');
        $token = $this->reviewToken($manager, 'approve', $chosen->id);

        $this->post(route('admin.signups.process'), ['token' => $token])->assertSessionHasNoErrors();

        $created = User::query()->where('username', $chosen->username)->sole();
        $this->assertSame('manager', $created->role);
        $this->assertTrue(Hash::check(self::PASSWORD, $created->password));
        $this->assertDatabaseMissing('pending_signup_requests', ['id' => $chosen->id]);
        $this->assertDatabaseHas('pending_signup_requests', ['id' => $other->id]);
    }

    public function test_approved_worker_and_manager_log_in_with_their_chosen_password_to_their_own_home(): void
    {
        $manager = User::factory()->manager()->create();
        $this->pendingRequest('approved.worker');
        $this->pendingRequest('approved.manager', 'manager');
        $review = app(SignupService::class)->review($manager, 'approve');
        app(SignupService::class)->process($manager, $review['token']);

        foreach (['worker' => 'worker.home', 'manager' => 'admin.users.index'] as $role => $homeRoute) {
            auth()->forgetGuards();
            $this->post(route('login.store'), ['username' => 'approved.'.$role, 'password' => self::PASSWORD])
                ->assertSessionHasNoErrors()->assertRedirect(route($homeRoute));
            $this->assertAuthenticatedAs(User::query()->where('username', 'approved.'.$role)->sole());
            $this->post(route('logout'))->assertRedirect();
        }
    }

    public function test_bulk_approval_keeps_conflicts_pending_and_never_changes_existing_accounts(): void
    {
        $manager = User::factory()->manager()->create();
        $conflict = $this->pendingRequest('became.taken', 'manager');
        $valid = $this->pendingRequest('still.available');
        $token = $this->reviewToken($manager, 'approve');
        $existing = app(AccountManagementService::class)->create($manager, array_replace($this->applicantAttributes(), [
            'username' => $conflict->username, 'role' => 'worker', 'password' => 'Existing-account-password-83',
        ]));
        $original = $existing->fresh()->getRawOriginal();

        $this->post(route('admin.signups.process'), ['token' => $token])->assertSessionHasNoErrors();

        $this->assertSame($original, $existing->fresh()->getRawOriginal());
        $this->assertNotEmpty($conflict->fresh()->review_error);
        $this->assertDatabaseHas('users', ['username' => $valid->username, 'role' => 'worker']);
        $this->assertDatabaseCount('pending_signup_requests', 1);
        $this->assertDatabaseCount('users', 3);
    }

    public function test_delete_all_removes_pending_requests_of_both_roles_and_preserves_real_accounts(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $originalWorker = $worker->fresh()->getRawOriginal();
        $this->pendingRequest('delete.worker');
        $this->pendingRequest('delete.manager', 'manager');
        $token = $this->reviewToken($manager, 'delete');
        $late = $this->pendingRequest('late.worker');

        $this->post(route('admin.signups.process'), ['token' => $token])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('pending_signup_requests', 1);
        $this->assertDatabaseHas('pending_signup_requests', ['id' => $late->id]);
        $this->assertDatabaseCount('users', 2);
        $this->assertSame($originalWorker, $worker->fresh()->getRawOriginal());
    }

    public function test_a_deleted_request_can_be_resubmitted_without_an_old_confirmation_processing_it(): void
    {
        $this->openSignup('worker');
        $manager = User::factory()->manager()->create();
        $original = $this->pendingRequest('repeat.applicant', 'manager');
        $review = app(SignupService::class)->review($manager, 'approve');
        $original->delete();
        $this->post(route('signup.store', 'worker'), $this->applicantAttributes(['username' => 'repeat.applicant']))
            ->assertSessionHasNoErrors();
        $resubmitted = PendingSignupRequest::query()->sole();

        app(SignupService::class)->process($manager, $review['token']);

        $this->assertNotSame($original->id, $resubmitted->id);
        $this->assertDatabaseHas('pending_signup_requests', ['id' => $resubmitted->id, 'role' => 'worker']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_repeating_an_approval_confirmation_creates_no_duplicate_accounts(): void
    {
        $manager = User::factory()->manager()->create();
        $this->pendingRequest('approve.once', 'manager');
        $token = $this->reviewToken($manager, 'approve');
        $this->post(route('admin.signups.process'), ['token' => $token])->assertSessionHasNoErrors();

        $this->post(route('admin.signups.process'), ['token' => $token])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('pending_signup_requests', 0);
    }

    public function test_a_confirmation_cannot_be_processed_by_a_different_manager(): void
    {
        $manager = User::factory()->manager()->create();
        $otherManager = User::factory()->manager()->create();
        $pending = $this->pendingRequest('wrong.actor');
        $token = $this->reviewToken($manager, 'approve');

        // These are different browsers: do not carry the first manager's
        // auth.session password fingerprint into the second manager's session.
        $this->flushSession();
        $this->actingAs($otherManager)->post(route('admin.signups.process'), ['token' => $token])
            ->assertSessionHasErrors([], null, 'signupManagement');

        $this->assertDatabaseHas('pending_signup_requests', ['id' => $pending->id]);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_an_expired_confirmation_requires_a_fresh_review_without_creating_accounts(): void
    {
        $manager = User::factory()->manager()->create();
        $pending = $this->pendingRequest('expired.review');
        $token = $this->reviewToken($manager, 'approve');
        $this->travel(31)->minutes();

        $this->post(route('admin.signups.process'), ['token' => $token])->assertSessionHasErrors([], null, 'signupManagement');

        $this->assertDatabaseHas('pending_signup_requests', ['id' => $pending->id]);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_a_tampered_confirmation_does_not_create_or_delete_any_account(): void
    {
        $manager = User::factory()->manager()->create();
        $pending = $this->pendingRequest('tampered.review');
        $token = $this->reviewToken($manager, 'approve');

        $this->post(route('admin.signups.process'), ['token' => substr($token, 0, -8).'tampered'])
            ->assertSessionHasErrors([], null, 'signupManagement');

        $this->assertDatabaseHas('pending_signup_requests', ['id' => $pending->id]);
        $this->assertDatabaseCount('users', 1);
    }

    #[DataProvider('malformedReviewPayloads')]
    public function test_even_an_authenticated_but_malformed_confirmation_payload_fails_safely(array $payloadOverrides): void
    {
        $manager = User::factory()->manager()->create();
        $pending = $this->pendingRequest('malformed.review');
        $token = $this->reviewToken($manager, 'approve');
        $payload = json_decode(Crypt::decryptString($token), true);
        $payload = array_replace($payload, $payloadOverrides);
        $malformedToken = Crypt::encryptString(json_encode($payload));

        $this->post(route('admin.signups.process'), ['token' => $malformedToken])
            ->assertSessionHasErrors([], null, 'signupManagement')
            ->assertSessionMissing('_old_input.token');

        $this->assertDatabaseHas('pending_signup_requests', ['id' => $pending->id]);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_an_old_confirmation_cannot_be_reused_after_the_same_manager_logs_in_with_a_new_session_version(): void
    {
        $manager = User::factory()->manager()->create();
        $pending = $this->pendingRequest('old.version');
        $token = $this->reviewToken($manager, 'approve');
        User::query()->whereKey($manager->id)->increment('session_version');
        $manager = $manager->fresh();

        $this->withSession(['auth_session_version' => $manager->session_version])->actingAs($manager)
            ->post(route('admin.signups.process'), ['token' => $token])
            ->assertSessionHasErrors([], null, 'signupManagement');

        $this->assertDatabaseHas('pending_signup_requests', ['id' => $pending->id]);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_unexpected_failure_rolls_back_every_created_account_and_pending_deletion(): void
    {
        $manager = User::factory()->manager()->create();
        $first = $this->pendingRequest('rollback.first');
        $second = $this->pendingRequest('rollback.second', 'manager');
        $review = app(SignupService::class)->review($manager, 'approve');
        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected): void {
            if (! $injected && str_starts_with(strtolower($query->sql), 'insert into `users`')) {
                $injected = true;
                throw new RuntimeException('Synthetic failure after the first account insert.');
            }
        });

        try {
            app(SignupService::class)->process($manager, $review['token']);
            $this->fail('The injected failure should leave the whole batch unchanged.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic failure after the first account insert.', $exception->getMessage());
        }

        $this->assertTrue($injected);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('pending_signup_requests', ['id' => $first->id]);
        $this->assertDatabaseHas('pending_signup_requests', ['id' => $second->id]);
    }

    public function test_the_service_rejects_an_actors_revoked_session_before_review_processing_or_switch_changes(): void
    {
        $manager = User::factory()->manager()->create();
        $pending = $this->pendingRequest('stale.actor');
        $review = app(SignupService::class)->review($manager, 'approve');
        User::query()->whereKey($manager->id)->increment('session_version');

        foreach (['review', 'process', 'setting'] as $operation) {
            try {
                match ($operation) {
                    'review' => app(SignupService::class)->review($manager, 'delete'),
                    'process' => app(SignupService::class)->process($manager, $review['token']),
                    'setting' => app(SignupService::class)->setEnabled($manager, 'worker', true),
                };
                $this->fail('Revoked manager authority must be rejected inside the shared gate.');
            } catch (AuthorizationException) {
                $this->assertDatabaseHas('pending_signup_requests', ['id' => $pending->id]);
            }
        }

        $this->assertDatabaseCount('users', 1);
        $this->assertFalse(SignupSetting::query()->findOrFail('worker')->is_enabled);
    }

    public static function signupRoles(): array
    {
        return ['worker signup' => ['worker'], 'manager signup' => ['manager']];
    }

    public static function optionalBirthdays(): array
    {
        return ['omitted birthday' => [[]], 'blank birthday' => [['birth_date' => '']]];
    }

    public static function existingAccountTypes(): array
    {
        return [
            'active worker' => ['worker', true], 'inactive worker' => ['worker', false],
            'active manager' => ['manager', true], 'inactive manager' => ['manager', false],
        ];
    }

    public static function invalidApplicantFields(): array
    {
        return [
            'short username' => ['username', 'ab'],
            'long username' => ['username', str_repeat('a', 61)],
            'Hebrew username' => ['username', 'שם.משתמש'],
            'username containing a space' => ['username', 'first last'],
            'missing first name' => ['first_name', ''],
            'oversized last name' => ['last_name', str_repeat('א', 61)],
            'missing gender' => ['gender', ''],
            'invalid gender' => ['gender', 'invalid'],
            'invalid birthday' => ['birth_date', 'not-a-date'],
            'nonexistent leap day' => ['birth_date', '2001-02-29'],
            'future birthday' => ['birth_date', '2999-01-01'],
            'eleven character password' => ['password', 'abcdefghijk'],
            'seventy-three byte password' => ['password', str_repeat('x', 73)],
            'Hebrew password above byte limit' => ['password', str_repeat('א', 37)],
            'NUL in password' => ['password', "password\0with-nul-93"],
            'mismatched confirmation' => ['password', 'Different-password-84', false],
        ];
    }

    public static function injectedAuthorityFields(): array
    {
        return [
            'chosen role' => ['role', 'manager'], 'activation' => ['is_active', true],
            'another account id' => ['user_id', 1], 'account id' => ['id', 1],
            'stored photo path' => ['photo_path', '../../unapproved.php'],
            'remember token' => ['remember_token', 'injected'],
            'session version' => ['session_version', 999],
            'review error' => ['review_error', 'injected'],
        ];
    }

    public static function invalidManagerReviewFields(): array
    {
        return [
            'unknown action' => [['action' => 'reactivate', 'scope' => 'all']],
            'unknown scope' => [['action' => 'approve', 'scope' => 'page']],
            'missing selected request' => [['action' => 'approve', 'scope' => 'single']],
            'all with a selected request' => [['action' => 'approve', 'scope' => 'all', 'request_id' => 1]],
            'client role change' => [['action' => 'approve', 'scope' => 'all', 'role' => 'manager']],
        ];
    }

    public static function malformedReviewPayloads(): array
    {
        return [
            'another purpose' => [['purpose' => 'another-workflow']],
            'negative snapshot bound' => [['max_id' => -1]],
            'unrecognized action' => [['action' => 'reactivate']],
            'array expiry' => [['expires_at' => []]],
            'string actor id' => [['actor_id' => '1']],
            'noninteger role count' => [['manager_count' => '0']],
        ];
    }

    /** Valid public fields; each scenario overrides only the rule under review. */
    private function applicantAttributes(array $overrides = []): array
    {
        return array_replace([
            'username' => 'new.applicant', 'first_name' => 'דנה', 'last_name' => 'כהן',
            'gender' => 'female', 'birth_date' => '2000-02-29',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ], $overrides);
    }

    private function openSignup(string $role): void
    {
        SignupSetting::query()->updateOrCreate(['role' => $role], ['is_enabled' => true]);
    }

    /** Fixture uses an explicit hash, matching persisted validated submissions. */
    private function pendingRequest(string $username, string $role = 'worker'): PendingSignupRequest
    {
        return PendingSignupRequest::query()->create([
            'username' => $username, 'first_name' => 'דנה', 'last_name' => 'כהן',
            'gender' => 'female', 'birth_date' => null, 'role' => $role,
            'password' => Hash::make(self::PASSWORD),
        ]);
    }

    /** Review is a read-only POST; only its returned token authorizes processing. */
    private function reviewToken(User $manager, string $action, ?int $requestId = null): string
    {
        $attributes = ['action' => $action, 'scope' => $requestId === null ? 'all' : 'single'];
        if ($requestId !== null) {
            $attributes['request_id'] = $requestId;
        }

        $response = $this->actingAs($manager)->post(route('admin.signups.review'), $attributes)
            ->assertOk()->assertDontSee(self::PASSWORD);

        return $response->viewData('review')['token'];
    }

    /** Form actions may target signup; hyperlinks must never advertise its URL. */
    private function assertNoSignupHyperlinks(string $html): void
    {
        $this->assertDoesNotMatchRegularExpression('/<a\b[^>]*\bhref\s*=\s*["\'][^"\']*\/signup(?:\/|[?"\'])/i', $html);
    }
}
