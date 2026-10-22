<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_create_a_worker_with_a_hashed_password(): void
    {
        $manager = User::factory()->manager()->create();
        $attributes = $this->accountAttributes();
        $attributes['username'] = '  NEW.WORKER  ';

        $this->actingAs($manager)->post(route('admin.users.store'), $attributes)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.index'));

        $worker = User::query()->where('username', 'new.worker')->firstOrFail();
        $this->assertSame('worker', $worker->role);
        $this->assertTrue($worker->is_active);
        $this->assertSame('2000-03-12', $worker->birth_date->format('Y-m-d'));
        $this->assertTrue(Hash::check($attributes['password'], $worker->password));
        $this->assertDatabaseCount('users', 2);
        $this->actingAs($manager)->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('new.worker');
    }

    public function test_manager_can_create_another_manager(): void
    {
        $manager = User::factory()->manager()->create();
        $attributes = $this->accountAttributes(['role' => 'manager', 'birth_date' => '']);

        $this->actingAs($manager)->post(route('admin.users.store'), $attributes)
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'username' => $attributes['username'],
            'role' => 'manager',
            'is_active' => true,
            'birth_date' => null,
        ]);
    }

    public function test_username_uniqueness_is_enforced_after_case_and_whitespace_normalization(): void
    {
        $manager = User::factory()->manager()->create();
        User::factory()->create(['username' => 'existing.user']);

        $this->actingAs($manager)->post(route('admin.users.store'), $this->accountAttributes([
            'username' => '  EXISTING.USER  ',
        ]))->assertSessionHasErrors('username');

        $this->assertDatabaseCount('users', 2);
    }

    public function test_database_also_prevents_duplicate_usernames(): void
    {
        User::factory()->create(['username' => 'existing.user']);

        $this->expectException(QueryException::class);

        User::factory()->create(['username' => 'existing.user']);
    }

    #[DataProvider('invalidAccountFields')]
    public function test_invalid_account_fields_are_rejected(string $field, mixed $invalidValue, bool $confirmPassword = true): void
    {
        $manager = User::factory()->manager()->create();
        $attributes = $this->accountAttributes([$field => $invalidValue]);

        if ($field === 'password' && $confirmPassword) {
            // Length scenarios must fail the length rule, rather than merely confirmation.
            $attributes['password_confirmation'] = $invalidValue;
        }

        $this->actingAs($manager)->post(route('admin.users.store'), $attributes)
            ->assertSessionHasErrors($field);

        $this->assertDatabaseCount('users', 1);
    }

    public static function invalidAccountFields(): array
    {
        return [
            'short username' => ['username', 'ab'],
            'long username' => ['username', str_repeat('a', 61)],
            'non ASCII username' => ['username', 'שם.משתמש'],
            'username containing a space' => ['username', 'first last'],
            'missing first name' => ['first_name', ''],
            'long first name' => ['first_name', str_repeat('א', 61)],
            'missing last name' => ['last_name', ''],
            'long last name' => ['last_name', str_repeat('ב', 61)],
            'missing gender' => ['gender', ''],
            'unrecognized gender' => ['gender', 'unapproved'],
            'unrecognized role' => ['role', 'superadmin'],
            'future birth date' => ['birth_date', '2999-01-01'],
            'invalid birth date' => ['birth_date', 'not-a-date'],
            'short password' => ['password', 'short'],
            'long password' => ['password', str_repeat('x', 73)],
            'password exceeding bcrypt byte limit' => ['password', str_repeat('א', 40)],
            'mismatched password confirmation' => ['password', 'Different-password-83', false],
        ];
    }

    public function test_worker_cannot_create_or_deactivate_accounts(): void
    {
        $worker = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($worker)->post(route('admin.users.store'), $this->accountAttributes())
            ->assertForbidden();
        $this->actingAs($worker)->patch(route('admin.users.deactivate', $target), [
            'confirm_deactivation' => '1',
        ])->assertForbidden();

        $this->assertDatabaseCount('users', 2);
        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_deactivation_requires_explicit_confirmation(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();

        $this->actingAs($manager)->patch(route('admin.users.deactivate', $worker))
            ->assertSessionHasErrors('confirm_deactivation');

        $this->assertTrue($worker->fresh()->is_active);
    }

    public function test_deactivation_retains_the_account_and_revokes_its_database_sessions(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $otherWorker = User::factory()->create();
        $this->insertDatabaseSession('worker-session-one', $worker);
        $this->insertDatabaseSession('worker-session-two', $worker);
        $this->insertDatabaseSession('other-worker-session', $otherWorker);

        $this->actingAs($manager)->patch(route('admin.users.deactivate', $worker), [
            'confirm_deactivation' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['id' => $worker->id, 'is_active' => false]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $worker->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'other-worker-session']);
        $this->assertDatabaseCount('users', 3);

        // A repeated request remains harmless and never removes historical identity.
        $this->actingAs($manager)->patch(route('admin.users.deactivate', $worker), [
            'confirm_deactivation' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['id' => $worker->id, 'is_active' => false]);
    }

    public function test_the_last_active_manager_cannot_be_deactivated_even_if_inactive_managers_exist(): void
    {
        $manager = User::factory()->manager()->create();
        User::factory()->manager()->inactive()->create();

        $this->actingAs($manager)->patch(route('admin.users.deactivate', $manager), [
            'confirm_deactivation' => '1',
        ])->assertSessionHasErrors();

        $this->assertTrue($manager->fresh()->is_active);
        $this->assertAuthenticatedAs($manager);
    }

    public function test_a_manager_can_deactivate_another_manager_when_an_active_manager_remains(): void
    {
        $manager = User::factory()->manager()->create();
        $otherManager = User::factory()->manager()->create();

        $this->actingAs($manager)->patch(route('admin.users.deactivate', $otherManager), [
            'confirm_deactivation' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($manager->fresh()->is_active);
        $this->assertFalse($otherManager->fresh()->is_active);
    }

    public function test_account_service_rechecks_a_stale_actors_active_status_before_mutation(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        User::query()->whereKey($manager->id)->update(['is_active' => false]);

        $this->expectException(AuthorizationException::class);

        app(AccountManagementService::class)->deactivate($manager, $worker);
    }

    public function test_user_supplied_activation_and_photo_fields_are_not_mass_assigned(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->post(route('admin.users.store'), $this->accountAttributes([
            'is_active' => false,
            'photo_path' => '../../unapproved.php',
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'username' => 'new.worker',
            'is_active' => true,
            'photo_path' => null,
        ]);
    }

    public function test_dashboard_escapes_names_and_contains_only_implemented_sections(): void
    {
        $manager = User::factory()->manager()->create();
        User::factory()->create(['first_name' => '<script>alert(1)</script>']);

        $this->actingAs($manager)->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('id="events"', false)
            ->assertSee('id="blocks"', false)
            ->assertSee('id="reports"', false)
            ->assertSee('id="desks"', false)
            ->assertSee('id="receipts"', false)
            ->assertDontSee('worker-profile-prototype.html', false)
            ->assertDontSee('localStorage', false);
    }

    #[DataProvider('managerGenderLabels')]
    public function test_manager_role_label_respects_the_selected_gender(string $gender, string $expectedLabel): void
    {
        $manager = User::factory()->manager()->make(['gender' => $gender]);

        $this->assertSame($expectedLabel, $manager->roleLabel());
    }

    public static function managerGenderLabels(): array
    {
        return [
            'male' => ['male', 'מנהל'],
            'female' => ['female', 'מנהלת'],
            'other' => ['other', 'מנהל/ת'],
        ];
    }

    /** Provide a complete valid request so each scenario changes only its own inputs. */
    private function accountAttributes(array $overrides = []): array
    {
        return array_replace([
            'username' => 'new.worker',
            'first_name' => 'דנה',
            'last_name' => 'כהן',
            'gender' => 'female',
            'birth_date' => '2000-03-12',
            'role' => 'worker',
            'password' => 'Chosen-test-password-93',
            'password_confirmation' => 'Chosen-test-password-93',
        ], $overrides);
    }

    private function insertDatabaseSession(string $sessionId, User $user): void
    {
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->id,
            'payload' => base64_encode(serialize([])),
            'last_activity' => time(),
        ]);
    }
}
