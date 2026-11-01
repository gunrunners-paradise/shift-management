<?php

namespace Tests\Feature;

use App\Models\Desk;
use App\Models\MonthlyReceipt;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\AccountManagementService;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesDeskReservations;
use Tests\Concerns\CreatesShiftRecords;
use Tests\TestCase;

class ManagerPasswordResetTest extends TestCase
{
    use CreatesDeskReservations, CreatesShiftRecords, RefreshDatabase;

    private const OLD_PASSWORD = 'Previous-test-password-93';

    private const NEW_PASSWORD = 'Replacement-test-password-84';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00', 'Asia/Jerusalem'));
    }

    #[DataProvider('targetAccounts')]
    public function test_an_active_manager_changes_another_accounts_password_without_changing_role_profile_or_activation(string $role, bool $active): void
    {
        $manager = User::factory()->manager()->create();
        $target = User::factory()->create([
            'role' => $role, 'is_active' => $active, 'password' => self::OLD_PASSWORD,
            'first_name' => 'Existing', 'last_name' => 'Account', 'gender' => 'female',
            'birth_date' => '1990-03-15', 'photo_path' => 'avatars/existing-photo.png',
            'remember_token' => str_repeat('r', 60), 'session_version' => 4,
        ])->refresh();
        $original = $target->getRawOriginal();
        $managerOriginal = $manager->refresh()->getRawOriginal();

        $response = $this->actingAs($manager)->patch(route('admin.users.password.update', $target), $this->passwordInput($target));

        $response->assertSessionHasNoErrors()->assertRedirect();
        $target->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $target->password));
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, $target->password));
        $this->assertNotSame(self::NEW_PASSWORD, $target->password);
        $this->assertSame(5, $target->session_version);
        $this->assertNull($target->remember_token);

        foreach (['id', 'username', 'first_name', 'last_name', 'gender', 'birth_date', 'photo_path', 'role', 'is_active', 'created_at'] as $field) {
            $this->assertSame($original[$field], $target->getRawOriginal($field), $field.' must survive the password change.');
        }

        $this->assertSame($managerOriginal, $manager->fresh()->getRawOriginal());
        $this->assertAuthenticatedAs($manager);
        $this->get(route('admin.users.index'))->assertOk();
        $this->assertAuthenticatedAs($manager);
    }

    public static function targetAccounts(): array
    {
        return [
            'active worker' => ['worker', true],
            'inactive worker' => ['worker', false],
            'active manager' => ['manager', true],
            'inactive manager' => ['manager', false],
        ];
    }

    #[DataProvider('activeRoleDestinations')]
    public function test_the_active_account_signs_in_with_the_new_password_and_can_voluntarily_change_it_in_its_profile(string $role, string $destination): void
    {
        $manager = User::factory()->manager()->create();
        $target = User::factory()->create(['role' => $role, 'password' => self::OLD_PASSWORD]);
        $this->actingAs($manager)->patch(route('admin.users.password.update', $target), $this->passwordInput($target))
            ->assertSessionHasNoErrors();
        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->post(route('login.store'), ['username' => $target->username, 'password' => self::OLD_PASSWORD])
            ->assertSessionHasErrors('username');
        $this->assertGuest();
        $this->post(route('login.store'), ['username' => $target->username, 'password' => self::NEW_PASSWORD])
            ->assertSessionHasNoErrors()->assertRedirect(route($destination));
        $this->assertAuthenticatedAs($target);
        $this->assertSame(1, session('auth_session_version'));
        $this->get(route($destination))->assertOk();
        $this->get(route('profile.edit'))->assertOk();

        $chosenPassword = 'Personally-chosen-password-75';
        $this->put(route('profile.password.update'), [
            'current_password' => self::NEW_PASSWORD, 'password' => $chosenPassword,
            'password_confirmation' => $chosenPassword,
        ])->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

        $this->assertTrue(Hash::check($chosenPassword, $target->fresh()->password));
        $this->get(route('profile.edit'))->assertOk();
    }

    public static function activeRoleDestinations(): array
    {
        return ['worker' => ['worker', 'worker.home'], 'manager' => ['manager', 'admin.users.index']];
    }

    #[DataProvider('inactiveRoles')]
    public function test_changing_an_inactive_accounts_password_does_not_allow_login_until_explicit_reactivation(string $role): void
    {
        $manager = User::factory()->manager()->create();
        $target = User::factory()->inactive()->create(['role' => $role, 'password' => self::OLD_PASSWORD]);
        app(AccountManagementService::class)->resetPassword($manager, $target, self::NEW_PASSWORD);

        $this->post(route('login.store'), ['username' => $target->username, 'password' => self::NEW_PASSWORD])
            ->assertSessionHasErrors('username');
        $this->assertGuest();
        $this->assertFalse($target->fresh()->is_active);

        app(AccountManagementService::class)->reactivate($manager, $target);
        $this->post(route('login.store'), ['username' => $target->username, 'password' => self::NEW_PASSWORD])
            ->assertSessionHasNoErrors()->assertRedirect(route($target->homeRoute()));
        $this->assertAuthenticatedAs($target);
    }

    public static function inactiveRoles(): array
    {
        return ['worker' => ['worker'], 'manager' => ['manager']];
    }

    public function test_password_reset_revokes_only_the_targets_stored_sessions_and_keeps_the_acting_manager_signed_in(): void
    {
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $manager = User::factory()->manager()->create(['password' => self::OLD_PASSWORD]);
        $target = User::factory()->manager()->create();
        $otherWorker = User::factory()->create();
        $this->insertSession('target-session-one', $target);
        $this->insertSession('target-session-two', $target);
        $this->insertSession('unrelated-worker-session', $otherWorker);

        // Establish an actual browser login before comparing its session ID.
        // StartSession has not initialized the browser session before HTTP runs.
        $this->post(route('login.store'), ['username' => $manager->username, 'password' => self::OLD_PASSWORD])
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.users.index'));
        $managerSessionId = session()->getId();
        $this->withCookie(config('session.cookie'), $managerSessionId)->get(route('admin.users.index'))->assertOk();
        $managerCsrfToken = session()->token();

        $this->withCookie(config('session.cookie'), $managerSessionId)
            ->patch(route('admin.users.password.update', $target), $this->passwordInput($target))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
        $this->assertDatabaseHas('sessions', ['id' => $managerSessionId, 'user_id' => $manager->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'unrelated-worker-session']);
        $this->assertSame($managerSessionId, session()->getId());
        $this->assertSame($managerCsrfToken, session()->token());

        // Reload authentication from the stored browser session rather than
        // relying on the previous request's already resolved guard instance.
        $this->app['auth']->forgetGuards();
        $this->withCookie(config('session.cookie'), $managerSessionId)->get(route('admin.users.index'))->assertOk();
        $this->assertAuthenticatedAs($manager);
    }

    public function test_a_late_write_of_a_revoked_database_session_cannot_restore_access_after_a_manager_password_reset(): void
    {
        // Replay the encrypted database-session payload, as an already running
        // browser request could write it after the reset deleted stored rows.
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create(['password' => self::OLD_PASSWORD]);
        $this->post(route('login.store'), ['username' => $worker->username, 'password' => self::OLD_PASSWORD])
            ->assertSessionHasNoErrors()->assertRedirect(route('worker.home'));
        $oldSessionId = session()->getId();
        $oldPayload = DB::table('sessions')->where('id', $oldSessionId)->value('payload');
        $this->assertIsString($oldPayload);
        app(AccountManagementService::class)->resetPassword($manager, $worker, self::NEW_PASSWORD);
        $this->assertDatabaseMissing('sessions', ['user_id' => $worker->id]);

        $handler = new DatabaseSessionHandler(DB::connection(), 'sessions', 43200, $this->app);
        $this->assertTrue($handler->write($oldSessionId, base64_decode($oldPayload, true)));
        $this->assertDatabaseHas('sessions', ['id' => $oldSessionId, 'user_id' => $worker->id]);
        $this->app['auth']->forgetGuards();

        $this->withCookie(config('session.cookie'), $oldSessionId)->get(route('worker.home'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertTrue($worker->fresh()->is_active);
        $this->assertSame(1, $worker->fresh()->session_version);
    }

    public function test_password_reset_preserves_owned_history_shared_records_and_the_profile_photo(): void
    {
        Storage::fake('local');
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create(['photo_path' => 'avatars/retained-worker.png']);
        Storage::disk('local')->put($worker->photo_path, 'Retained private profile image.');
        $otherWorker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();
        $this->createShiftRecord($worker, '2026-10-20');
        $this->createShiftRecord($worker, '2026-11-02');
        $this->createShiftRecord($worker, '2026-11-03', 'full', [
            'cancelled_at' => now(), 'cancelled_by' => $manager->id, 'cancellation_reason' => 'worker_canceled',
        ]);
        $this->createDeskReservation($worker, $desk, '2026-10-20');
        $this->createDeskReservation($worker, $desk, '2026-11-02');
        $this->createDeskReservation($worker, $desk, '2026-11-03', '10:00', '11:00', [
            'cancelled_at' => now(), 'cancelled_by' => $manager->id, 'cancellation_reason' => 'worker_canceled',
        ]);
        $this->createShiftRecord($otherWorker, '2026-11-02');
        MonthlyReceipt::create(['worker_id' => $worker->id, 'month' => '2026-11-01', 'has_receipts' => true]);
        $originalRecords = $this->historySnapshot();

        $this->actingAs($manager)->patch(route('admin.users.password.update', $worker), $this->passwordInput($worker))
            ->assertSessionHasNoErrors();

        $this->assertEquals($originalRecords, $this->historySnapshot());
        Storage::disk('local')->assertExists('avatars/retained-worker.png');
        $this->assertDatabaseCount('users', 3);
    }

    public function test_a_failure_while_revoking_sessions_rolls_back_the_password_hash_and_version_together(): void
    {
        $manager = User::factory()->manager()->create();
        $target = User::factory()->create(['password' => self::OLD_PASSWORD, 'remember_token' => str_repeat('r', 60)])->refresh();
        $original = $target->getRawOriginal();
        $this->insertSession('rollback-target-session', $target);

        // Throw after MariaDB executes the DELETE: rollback must restore both
        // its session row and the password/version write made earlier.
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'delete from `sessions`')) {
                throw new RuntimeException('Synthetic session-revocation failure.');
            }
        });
        $failed = false;

        try {
            app(AccountManagementService::class)->resetPassword($manager, $target, self::NEW_PASSWORD);
        } catch (RuntimeException) {
            $failed = true;
        }

        $this->assertTrue($failed);
        $this->assertSame($original, $target->fresh()->getRawOriginal());
        $this->assertDatabaseHas('sessions', ['id' => 'rollback-target-session', 'user_id' => $target->id]);
    }

    #[DataProvider('invalidPasswords')]
    public function test_invalid_passwords_do_not_change_the_hash_sessions_or_account_version(mixed $password, mixed $confirmation): void
    {
        $manager = User::factory()->manager()->create();
        $target = User::factory()->create(['password' => self::OLD_PASSWORD]);
        $oldHash = $target->password;
        $this->insertSession('retained-invalid-reset-session', $target);

        $this->actingAs($manager)->from(route('admin.users.index'))->patch(route('admin.users.password.update', $target), [
            'password' => $password, 'password_confirmation' => $confirmation, 'password_form_id' => (string) $target->id,
        ])->assertSessionHasErrors('password', null, 'passwordReset')->assertRedirect();

        $this->assertSame($oldHash, $target->fresh()->password);
        $this->assertSame(0, $target->fresh()->session_version);
        $this->assertDatabaseHas('sessions', ['id' => 'retained-invalid-reset-session']);
        $this->assertNull(session()->getOldInput('password'));
        $this->assertNull(session()->getOldInput('password_confirmation'));
    }

    public static function invalidPasswords(): array
    {
        return [
            'missing password' => [null, null],
            'empty password' => ['', ''],
            'eleven characters' => ['12345678901', '12345678901'],
            'more than seventy-two ASCII bytes' => [str_repeat('x', 73), str_repeat('x', 73)],
            'thirty-seven Hebrew characters exceed seventy-two bytes' => [str_repeat('א', 37), str_repeat('א', 37)],
            'different confirmation' => [self::NEW_PASSWORD, 'Another-test-password-75'],
            'missing confirmation' => [self::NEW_PASSWORD, null],
            'array password' => [['unexpected'], ['unexpected']],
            'NUL byte cannot be hashed by bcrypt' => ["Invalid\0password-84", "Invalid\0password-84"],
        ];
    }

    #[DataProvider('validPasswordBoundaries')]
    public function test_valid_password_boundaries_are_accepted_without_trimming_the_literal_password(string $password): void
    {
        $manager = User::factory()->manager()->create();
        $target = User::factory()->create();

        $this->actingAs($manager)->patch(route('admin.users.password.update', $target), $this->passwordInput($target, $password))
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check($password, $target->fresh()->password));
    }

    public static function validPasswordBoundaries(): array
    {
        return [
            'exactly twelve characters' => ['123456789012'],
            'exactly seventy-two ASCII bytes' => [str_repeat('x', 72)],
            'exactly seventy-two Hebrew bytes' => [str_repeat('א', 36)],
            'leading and trailing whitespace is literal' => ['  Literal-password-84  '],
        ];
    }

    #[DataProvider('unrelatedAccountFields')]
    public function test_the_password_action_rejects_unrelated_account_fields_without_applying_the_password_change(string $field, mixed $value): void
    {
        $manager = User::factory()->manager()->create();
        $target = User::factory()->create(['password' => self::OLD_PASSWORD])->refresh();
        $original = $target->getRawOriginal();
        $this->insertSession('retained-prohibited-input-session', $target);

        $this->actingAs($manager)->patch(route('admin.users.password.update', $target), $this->passwordInput($target) + [$field => $value])
            ->assertSessionHasErrors($field, null, 'passwordReset');

        $this->assertSame($original, $target->fresh()->getRawOriginal());
        $this->assertDatabaseHas('sessions', ['id' => 'retained-prohibited-input-session']);
    }

    public static function unrelatedAccountFields(): array
    {
        return [
            'username' => ['username', 'different.username'],
            'role' => ['role', 'manager'],
            'activation' => ['is_active', false],
            'target identity' => ['user_id', 99999],
            'worker identity' => ['worker_id', 99999],
            'first name' => ['first_name', 'Unapproved'],
            'last name' => ['last_name', 'Unapproved'],
            'gender' => ['gender', 'female'],
            'birthday' => ['birth_date', '1990-03-15'],
            'photo path' => ['photo_path', 'avatars/unapproved.png'],
            'photo value' => ['photo', 'unapproved-photo'],
            'forgotten current password is not required input' => ['current_password', self::OLD_PASSWORD],
        ];
    }

    public function test_a_password_that_looks_like_a_bcrypt_hash_is_treated_as_literal_plaintext(): void
    {
        $manager = User::factory()->manager()->create();
        $target = User::factory()->create();
        $literalPassword = Hash::make('Synthetic-source-password-93');

        $this->actingAs($manager)->patch(route('admin.users.password.update', $target), $this->passwordInput($target, $literalPassword))
            ->assertSessionHasNoErrors();

        $this->assertNotSame($literalPassword, $target->fresh()->password);
        $this->assertTrue(Hash::check($literalPassword, $target->fresh()->password));
        $this->assertFalse(Hash::check('Synthetic-source-password-93', $target->fresh()->password));
    }

    public function test_a_guest_cannot_change_an_accounts_password(): void
    {
        $target = User::factory()->create(['password' => self::OLD_PASSWORD]);

        $this->patch(route('admin.users.password.update', $target), $this->passwordInput($target))
            ->assertRedirect(route('login'));

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $target->fresh()->password));
    }

    public function test_a_worker_cannot_change_another_accounts_password_through_the_manager_endpoint(): void
    {
        $worker = User::factory()->create();
        $target = User::factory()->create(['password' => self::OLD_PASSWORD]);

        $this->actingAs($worker)->patch(route('admin.users.password.update', $target), $this->passwordInput($target))
            ->assertForbidden();

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $target->fresh()->password));
    }

    public function test_an_inactive_manager_cannot_change_another_accounts_password(): void
    {
        $manager = User::factory()->manager()->create();
        $target = User::factory()->create(['password' => self::OLD_PASSWORD]);
        $this->actingAs($manager);
        User::whereKey($manager->id)->update(['is_active' => false]);

        $this->patch(route('admin.users.password.update', $target), $this->passwordInput($target))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $target->fresh()->password));
    }

    public function test_the_manager_cannot_bypass_their_own_profiles_current_password_check(): void
    {
        $manager = User::factory()->manager()->create(['password' => self::OLD_PASSWORD]);

        $this->actingAs($manager)->patch(route('admin.users.password.update', $manager), $this->passwordInput($manager))
            ->assertForbidden();

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $manager->fresh()->password));
        $this->assertSame(0, $manager->fresh()->session_version);
    }

    public function test_the_service_also_refuses_resetting_the_managers_own_password(): void
    {
        $manager = User::factory()->manager()->create();

        $this->expectException(AuthorizationException::class);

        app(AccountManagementService::class)->resetPassword($manager, $manager, self::NEW_PASSWORD);
    }

    #[DataProvider('staleManagerChanges')]
    public function test_the_service_rechecks_the_managers_current_authority_and_login_version(string $change): void
    {
        $manager = User::factory()->manager()->create();
        $target = User::factory()->create(['password' => self::OLD_PASSWORD]);

        if ($change === 'deleted') {
            User::whereKey($manager->id)->delete();
        } else {
            $changes = match ($change) {
                'inactive' => ['is_active' => false],
                'worker' => ['role' => 'worker'],
                'version' => ['session_version' => 1],
            };
            User::whereKey($manager->id)->update($changes);
        }

        $rejected = false;

        try {
            app(AccountManagementService::class)->resetPassword($manager, $target, self::NEW_PASSWORD);
        } catch (AuthorizationException) {
            $rejected = true;
        }

        $this->assertTrue($rejected);
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $target->fresh()->password));
        $this->assertSame(0, $target->fresh()->session_version);
    }

    public static function staleManagerChanges(): array
    {
        return ['inactive' => ['inactive'], 'role removed' => ['worker'], 'deleted' => ['deleted'], 'revoked login' => ['version']];
    }

    public function test_a_deleted_target_returns_not_found_without_creating_an_account(): void
    {
        $manager = User::factory()->manager()->create();
        $target = User::factory()->inactive()->create();
        app(AccountDeletionService::class)->delete($manager, $target);

        $this->actingAs($manager)->patch(route('admin.users.password.update', $target), $this->passwordInput($target))
            ->assertNotFound();
        $this->patch('/admin/users/99999999/password', $this->passwordInput($target))->assertNotFound();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_a_manager_password_change_requires_a_valid_csrf_token(): void
    {
        $this->app->bind(PreventRequestForgery::class, EnforcedManagerPasswordResetForgeryProtection::class);
        $manager = User::factory()->manager()->create();
        $target = User::factory()->create(['password' => self::OLD_PASSWORD]);
        $this->actingAs($manager)->withSession(['_token' => 'correct-reset-token']);

        $this->patch(route('admin.users.password.update', $target), $this->passwordInput($target))->assertStatus(419);
        $this->patch(route('admin.users.password.update', $target), $this->passwordInput($target) + ['_token' => 'wrong-token'])
            ->assertStatus(419);
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $target->fresh()->password));

        $this->patch(route('admin.users.password.update', $target), $this->passwordInput($target) + ['_token' => 'correct-reset-token'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $target->fresh()->password));
    }

    public function test_the_dashboard_has_collapsed_native_password_forms_for_other_accounts_only(): void
    {
        $manager = User::factory()->manager()->create();
        $accounts = [
            User::factory()->create(), User::factory()->inactive()->create(),
            User::factory()->manager()->create(), User::factory()->manager()->inactive()->create(),
        ];

        $response = $this->actingAs($manager)->get(route('admin.users.index'))->assertOk();
        $xpath = $this->document($response->getContent());
        $ownAction = route('admin.users.password.update', ['user' => $manager, 'page' => 1]);
        $this->assertCount(0, $xpath->query('//form[@action="'.$ownAction.'"]'));

        foreach ($accounts as $account) {
            $action = route('admin.users.password.update', ['user' => $account, 'page' => 1]);
            $forms = $xpath->query('//form[@action="'.$action.'"]');
            $this->assertCount(1, $forms);
            $form = $forms->item(0);
            $this->assertCount(1, $xpath->query('ancestor::details[not(@open)]', $form));
            $this->assertCount(1, $xpath->query('.//input[@type="hidden" and @name="_token"]', $form));
            $this->assertCount(1, $xpath->query('.//input[@name="_method" and @value="PATCH"]', $form));
            $this->assertCount(2, $xpath->query('.//input[@type="password" and @autocomplete="new-password" and @required]', $form));
            $this->assertCount(0, $xpath->query('.//input[@name="current_password" or @name="username" or @type="checkbox"]', $form));

            foreach ($xpath->query('.//input[@type="password"]', $form) as $input) {
                $this->assertFalse($input->hasAttribute('value'));
                $this->assertCount(1, $xpath->query('//label[@for="'.$input->getAttribute('id').'"]'));
                $this->assertCount(1, $xpath->query('//*[@id="'.$input->getAttribute('id').'"]'));
            }
        }
    }

    public function test_validation_opens_only_the_correct_account_form_without_populating_or_highlighting_the_create_form(): void
    {
        $manager = User::factory()->manager()->create(['password' => self::OLD_PASSWORD]);
        $target = User::factory()->create();
        $other = User::factory()->create();
        $invalidPassword = 'too-short';
        $this->startManagerBrowser($manager);
        $this->get(route('admin.users.index'))->assertOk();
        $this->from(route('admin.users.index'))->patch(route('admin.users.password.update', $target), [
            'password' => $invalidPassword, 'password_confirmation' => $invalidPassword,
            // Form tracking must come from the authenticated route, even when
            // a stale or tampered hidden input names a different visible card.
            'password_form_id' => (string) $other->id,
            '_error_bag' => 'default',
        ])->assertSessionHasErrors('password', null, 'passwordReset');

        $this->assertTrue(session('errors')->getBag('default')->isEmpty());
        $this->assertSame((string) $target->id, (string) session()->getOldInput('password_form_id'));
        $this->assertSame(['password_form_id'], array_keys(session()->getOldInput()));

        $response = $this->get(route('admin.users.index'))->assertOk()->assertDontSee($invalidPassword);
        $xpath = $this->document($response->getContent());
        $targetAction = route('admin.users.password.update', ['user' => $target, 'page' => 1]);
        $otherAction = route('admin.users.password.update', ['user' => $other, 'page' => 1]);
        $this->assertCount(1, $xpath->query('//details[@open]//form[@action="'.$targetAction.'"]'));
        $this->assertCount(0, $xpath->query('//details[@open]//form[@action="'.$otherAction.'"]'));
        $this->assertCount(0, $xpath->query('//form[@action="'.route('admin.users.store').'"]//*[@aria-invalid="true"]'));
        $this->assertCount(0, $xpath->query('//form[@action="'.$otherAction.'"]//*[@aria-invalid="true"]'));
        $this->assertCount(1, $xpath->query('//form[@action="'.$targetAction.'"]//input[@name="password" and @aria-invalid="true"]'));
        $this->assertNull(session()->getOldInput('password'));
        $this->assertNull(session()->getOldInput('password_confirmation'));
    }

    public function test_validation_and_success_return_to_the_accounts_own_list_page_without_following_an_external_referer(): void
    {
        $manager = User::factory()->manager()->create(['password' => self::OLD_PASSWORD]);
        User::factory()->count(9)->create();
        $target = User::factory()->create();
        $action = route('admin.users.password.update', ['user' => $target, 'page' => 2]);
        $returnUrl = route('admin.users.index', ['page' => 2]).'#account-list';

        $this->startManagerBrowser($manager);
        $this->from('https://untrusted.example/return')->patch($action, [
            'password' => 'short', 'password_confirmation' => 'short', 'password_form_id' => (string) $target->id,
        ])->assertSessionHasErrors('password', null, 'passwordReset')->assertRedirect($returnUrl);

        $response = $this->get($returnUrl)->assertOk()->assertSee($target->username);
        $xpath = $this->document($response->getContent());
        $this->assertCount(1, $xpath->query('//details[@open]//form[@action="'.$action.'"]'));

        $this->from('https://untrusted.example/return')->patch($action, $this->passwordInput($target))
            ->assertSessionHasNoErrors()->assertRedirect($returnUrl);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $target->fresh()->password));
    }

    /** Preserve a real session cookie between each browser-style HTTP request. */
    private function startManagerBrowser(User $manager): void
    {
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->post(route('login.store'), ['username' => $manager->username, 'password' => self::OLD_PASSWORD])
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.users.index'));
        $this->withCookie(config('session.cookie'), session()->getId());
    }

    /** Always submit exactly the target and its two new-password values. */
    private function passwordInput(User $target, string $password = self::NEW_PASSWORD): array
    {
        return ['password' => $password, 'password_confirmation' => $password, 'password_form_id' => (string) $target->id];
    }

    private function insertSession(string $id, User $user): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $user->id, 'payload' => base64_encode(serialize([])), 'last_activity' => time(),
        ]);
    }

    /** Compare unchanged historical rows, including their cancellation metadata. */
    private function historySnapshot(): array
    {
        $snapshot = [];

        foreach (['calendar_dates', 'shifts', 'desk_reservations', 'monthly_receipts', 'desks', 'blocked_dates', 'calendar_events'] as $table) {
            $orderColumn = in_array($table, ['calendar_dates', 'blocked_dates'], true) ? 'date' : 'id';
            $snapshot[$table] = DB::table($table)->orderBy($orderColumn)->get()->toArray();
        }

        return $snapshot;
    }

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previousErrors = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        return new DOMXPath($document);
    }
}

/** Exercise the real CSRF comparison rather than Laravel's unit-test bypass. */
class EnforcedManagerPasswordResetForgeryProtection extends PreventRequestForgery
{
    protected function runningUnitTests()
    {
        return false;
    }
}
