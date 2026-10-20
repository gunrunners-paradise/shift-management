<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_PASSWORD = 'Chosen-test-password-93';

    public function test_guests_are_sent_to_login_from_both_role_pages(): void
    {
        $this->get(route('home'))->assertRedirect(route('login'));
        $this->get(route('worker.home'))->assertRedirect(route('login'));
        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
    }

    public function test_login_has_a_guest_layout_without_authenticated_navigation(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('lang="he"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertSee('name="_token"', false)
            ->assertDontSee('התנתקות')
            ->assertDontSee('aria-controls="siteMenu"', false)
            ->assertDontSee('localStorage', false);
    }

    #[DataProvider('roleDestinations')]
    public function test_login_normalizes_username_and_redirects_each_role(string $role, string $destination): void
    {
        $user = User::factory()->create([
            'username' => 'chosen.user',
            'password' => self::TEST_PASSWORD,
            'role' => $role,
        ]);
        $this->withSession(['_token' => 'token-before-login']);
        $previousSessionId = session()->getId();

        $this->post(route('login.store'), [
            'username' => '  CHOSEN.USER  ',
            'password' => self::TEST_PASSWORD,
        ])->assertRedirect(route($destination));

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($previousSessionId, session()->getId());
        $this->assertNotSame('token-before-login', session()->token());
        $this->get(route('home'))->assertRedirect(route($destination));
    }

    public static function roleDestinations(): array
    {
        return [
            'worker' => ['worker', 'worker.home'],
            'manager' => ['manager', 'admin.users.index'],
        ];
    }

    public function test_wrong_unknown_and_inactive_credentials_share_a_generic_error(): void
    {
        User::factory()->create(['username' => 'active.worker', 'password' => self::TEST_PASSWORD]);
        User::factory()->inactive()->create(['username' => 'inactive.worker', 'password' => self::TEST_PASSWORD]);
        $credentials = [
            ['username' => 'active.worker', 'password' => 'incorrect-password'],
            ['username' => 'unknown.worker', 'password' => self::TEST_PASSWORD],
            ['username' => 'inactive.worker', 'password' => self::TEST_PASSWORD],
        ];
        $messages = [];

        foreach ($credentials as $attempt) {
            $this->post(route('login.store'), $attempt)->assertSessionHasErrors('username');
            $messages[] = session('errors')->first('username');
            $this->assertGuest();
        }

        $this->assertNotEmpty($messages[0]);
        $this->assertSame($messages[0], $messages[1]);
        $this->assertSame($messages[0], $messages[2]);
        $this->assertMatchesRegularExpression('/[א-ת]/u', $messages[0]);
    }

    public function test_sixth_attempt_for_one_username_and_ip_is_throttled_until_the_window_expires(): void
    {
        $worker = User::factory()->create(['password' => self::TEST_PASSWORD]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.store'), [
                'username' => $worker->username,
                'password' => 'incorrect-password',
            ])->assertSessionHasErrors('username');
        }

        $this->post(route('login.store'), [
            'username' => strtoupper($worker->username),
            'password' => self::TEST_PASSWORD,
        ])->assertSessionHasErrors('username');
        $this->assertGuest();

        $this->travel(61)->seconds();

        $this->post(route('login.store'), [
            'username' => $worker->username,
            'password' => self::TEST_PASSWORD,
        ])->assertRedirect(route('worker.home'));
        $this->assertAuthenticatedAs($worker);
    }

    public function test_ip_throttle_also_limits_attempts_spread_across_usernames(): void
    {
        $worker = User::factory()->create(['password' => self::TEST_PASSWORD]);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->post(route('login.store'), [
                'username' => 'unknown.'.$attempt,
                'password' => 'incorrect-password',
            ])->assertSessionHasErrors('username');
        }

        $this->post(route('login.store'), [
            'username' => $worker->username,
            'password' => self::TEST_PASSWORD,
        ])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_logout_invalidates_the_session_and_regenerates_its_csrf_token(): void
    {
        $worker = User::factory()->create();
        $this->actingAs($worker)->withSession(['_token' => 'token-before-logout', 'private-value' => 'remove-me']);
        $previousSessionId = session()->getId();

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull(session('private-value'));
        $this->assertNotSame($previousSessionId, session()->getId());
        $this->assertNotSame('token-before-logout', session()->token());
        $this->get(route('logout'))->assertMethodNotAllowed();
    }

    public function test_deactivation_is_checked_again_for_an_already_authenticated_user(): void
    {
        $worker = User::factory()->create();
        $this->actingAs($worker)->withSession(['private-value' => 'remove-me']);
        User::query()->whereKey($worker->id)->update(['is_active' => false]);

        // The guard still holds the earlier active model; middleware must reload current state.
        $this->get(route('worker.home'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull(session('private-value'));
    }

    public function test_each_role_is_forbidden_from_the_other_roles_page(): void
    {
        $worker = User::factory()->create();
        $manager = User::factory()->manager()->create();

        $this->actingAs($worker)->get(route('admin.users.index'))->assertForbidden();
        // These actors represent separate sign-ins, each with its own session hash.
        $this->flushSession();
        $this->actingAs($manager)->get(route('worker.home'))->assertForbidden();
    }

    public function test_self_registration_is_not_exposed(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'username' => 'unapproved.signup',
            'password' => self::TEST_PASSWORD,
        ])->assertNotFound();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_password_is_hashed_and_not_exposed_in_model_serialization(): void
    {
        $worker = User::factory()->create(['password' => self::TEST_PASSWORD]);

        $this->assertNotSame(self::TEST_PASSWORD, $worker->getRawOriginal('password'));
        $this->assertTrue(Hash::check(self::TEST_PASSWORD, $worker->password));
        $this->assertArrayNotHasKey('password', $worker->toArray());
        $this->assertArrayNotHasKey('remember_token', $worker->toArray());
    }

    public function test_login_rejects_a_multibyte_suffix_beyond_bcrypts_password_limit(): void
    {
        $chosenPassword = str_repeat('א', 36);
        $worker = User::factory()->create(['password' => $chosenPassword]);

        // Bcrypt ignores bytes beyond 72. Validation must reject that suffix
        // instead of authenticating it as though the operator entered the exact password.
        $this->post(route('login.store'), [
            'username' => $worker->username,
            'password' => $chosenPassword.'ב',
        ])->assertSessionHasErrors('password');
        $this->assertGuest();

        $this->post(route('login.store'), [
            'username' => $worker->username,
            'password' => $chosenPassword,
        ])->assertRedirect(route('worker.home'));
        $this->assertAuthenticatedAs($worker);
    }
}
