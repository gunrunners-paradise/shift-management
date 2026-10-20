<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FirstManagerSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_choose_the_first_managers_credentials_and_sign_in(): void
    {
        config(['app.url' => 'https://attendance.example.test']);
        $password = 'Operator-chosen-test-password-93';

        $this->artisan('app:create-manager')
            ->expectsQuestion('Username', 'chosen.manager')
            ->expectsQuestion('First name', 'רחל')
            ->expectsQuestion('Last name', 'לוי')
            ->expectsChoice('Gender', 'female', ['female', 'male', 'other'])
            ->expectsQuestion('Password', $password)
            ->expectsQuestion('Confirm password', $password)
            ->expectsOutputToContain('chosen.manager')
            ->expectsOutputToContain('manager')
            ->expectsOutputToContain('https://attendance.example.test/login')
            ->doesntExpectOutputToContain($password)
            ->assertSuccessful();

        $manager = User::query()->sole();
        $this->assertSame('chosen.manager', $manager->username);
        $this->assertSame('manager', $manager->role);
        $this->assertTrue($manager->is_active);
        $this->assertTrue(Hash::check($password, $manager->password));

        $this->post(route('login.store'), [
            'username' => 'chosen.manager',
            'password' => $password,
        ])->assertRedirect(route('admin.users.index'));
        $this->assertAuthenticatedAs($manager);
    }

    public function test_repeating_setup_never_duplicates_or_resets_an_existing_manager(): void
    {
        $manager = User::factory()->manager()->create([
            'username' => 'existing.manager',
            'password' => 'Existing-chosen-test-password-93',
        ]);
        $originalPasswordHash = $manager->password;

        // No question expectations: an existing manager must be detected before prompting.
        $this->artisan('app:create-manager')
            ->expectsOutputToContain('A manager already exists. No account or password was changed.')
            ->expectsOutputToContain('existing.manager')
            ->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($originalPasswordHash, $manager->fresh()->password);
    }

    public function test_setup_does_not_replace_an_inactive_manager(): void
    {
        $manager = User::factory()->manager()->inactive()->create();
        $originalPasswordHash = $manager->password;

        $this->artisan('app:create-manager')
            ->expectsOutputToContain('A manager already exists. No account or password was changed.')
            ->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($originalPasswordHash, $manager->fresh()->password);
        $this->assertFalse($manager->fresh()->is_active);
    }

    public function test_first_manager_password_must_be_confirmed(): void
    {
        $this->artisan('app:create-manager')
            ->expectsQuestion('Username', 'chosen.manager')
            ->expectsQuestion('First name', 'רחל')
            ->expectsQuestion('Last name', 'לוי')
            ->expectsChoice('Gender', 'other', ['female', 'male', 'other'])
            ->expectsQuestion('Password', 'Operator-chosen-test-password-93')
            ->expectsQuestion('Confirm password', 'Different-test-password-93')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
