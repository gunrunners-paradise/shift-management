<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ProfileService;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT_PASSWORD = 'Current-test-password-93';

    private const NEW_PASSWORD = 'Newly-chosen-test-password-84';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Jerusalem'));
    }

    #[DataProvider('roles')]
    public function test_each_role_has_an_own_profile_with_separate_details_and_password_forms(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $otherUser = User::factory()->create(['username' => 'private.other.account']);

        $response = $this->actingAs($user)->get(route('profile.edit', ['user_id' => $otherUser->id]));

        $response->assertOk()->assertSee($user->fullName())->assertDontSee($otherUser->username);
        $document = $this->document($response->getContent());
        $this->assertCount(1, $document->query('//form[@id="profile-details-form"]'));
        $this->assertCount(1, $document->query('//form[@id="profile-password-form"]'));
        $this->assertCount(1, $document->query('//input[@id="profile-username" and @readonly and not(@name)]'));
        $this->assertCount(1, $document->query('//input[@name="current_password" and @autocomplete="current-password"]'));
        $this->assertCount(0, $document->query('//form[@id="profile-details-form"]//input[@name="password"]'));
    }

    #[DataProvider('roles')]
    public function test_each_role_can_change_own_details_without_changing_identity_or_authority(string $role): void
    {
        $user = User::factory()->create(['role' => $role, 'password' => self::CURRENT_PASSWORD]);
        $originalUsername = $user->username;
        $originalHash = $user->password;

        $this->actingAs($user)->patch(route('profile.update'), $this->details())
            ->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertSame('שם מעודכן', $user->first_name);
        $this->assertSame('משפחה', $user->last_name);
        $this->assertSame('female', $user->gender);
        $this->assertSame('2000-02-29', $user->birth_date->format('Y-m-d'));
        $this->assertSame($originalUsername, $user->username);
        $this->assertSame($originalHash, $user->password);
        $this->assertSame($role, $user->role);
        $this->assertTrue($user->is_active);
    }

    public static function roles(): array
    {
        return [['worker'], ['manager']];
    }

    #[DataProvider('protectedFields')]
    public function test_profile_rejects_account_authority_and_ownership_injection(string $field, mixed $value): void
    {
        foreach (['worker', 'manager'] as $role) {
            $user = User::factory()->create(['role' => $role, 'first_name' => 'שם מקורי']);
            $this->flushSession();

            $this->actingAs($user)->patch(route('profile.update'), $this->details([$field => $value]))
                ->assertSessionHasErrors($field);

            $this->assertSame('שם מקורי', $user->fresh()->first_name);
            $this->assertSame($role, $user->fresh()->role);
            $this->assertTrue($user->fresh()->is_active);
        }
    }

    public static function protectedFields(): array
    {
        return [
            'username' => ['username', 'different.username'],
            'role' => ['role', 'manager'],
            'active flag' => ['is_active', false],
            'photo path' => ['photo_path', '../private-file.php'],
            'user identity' => ['user_id', 999999],
            'worker identity' => ['worker_id', 999999],
        ];
    }

    #[DataProvider('invalidDetails')]
    public function test_profile_rejects_invalid_personal_details(string $field, mixed $value): void
    {
        $user = User::factory()->create(['first_name' => 'שם מקורי']);

        $this->actingAs($user)->patch(route('profile.update'), $this->details([$field => $value]))
            ->assertSessionHasErrors($field);

        $this->assertSame('שם מקורי', $user->fresh()->first_name);
    }

    public static function invalidDetails(): array
    {
        return [
            'missing name' => ['first_name', ''],
            'long last name' => ['last_name', str_repeat('א', 61)],
            'unknown gender' => ['gender', 'unapproved'],
            'future birthday' => ['birth_date', '2026-10-21'],
            'invalid leap date' => ['birth_date', '2001-02-29'],
        ];
    }

    public function test_birthday_can_be_cleared_and_names_are_escaped(): void
    {
        $user = User::factory()->create(['birth_date' => '2000-02-29']);

        $this->actingAs($user)->patch(route('profile.update'), $this->details([
            'first_name' => '<script>alert(1)</script>',
            'birth_date' => '',
        ]))->assertSessionHasNoErrors();

        $this->assertNull($user->fresh()->birth_date);
        $this->get(route('profile.edit'))->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_no_role_can_target_another_users_profile_through_an_id_route(): void
    {
        $otherUser = User::factory()->create();

        foreach (['worker', 'manager'] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            $this->actingAs($actor)->get('/profile/'.$otherUser->id)->assertNotFound();
            $this->patch('/profile/'.$otherUser->id, $this->details())->assertNotFound();
        }

        $this->assertNotSame('שם מעודכן', $otherUser->fresh()->first_name);
    }

    public function test_guests_cannot_read_or_update_profiles(): void
    {
        $this->get(route('profile.edit'))->assertRedirect(route('login'));
        $this->patch(route('profile.update'), $this->details())->assertRedirect(route('login'));
        $this->put(route('profile.password.update'), [])->assertRedirect(route('login'));
    }

    public function test_wrong_current_password_preserves_the_hash_and_other_sessions(): void
    {
        $user = User::factory()->create(['password' => self::CURRENT_PASSWORD]);
        $originalHash = $user->password;
        $this->insertSession('other-device-session', $user);

        $this->actingAs($user)->put(route('profile.password.update'), [
            'current_password' => 'Incorrect-current-password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors('current_password');

        $this->assertSame($originalHash, $user->fresh()->password);
        $this->assertDatabaseHas('sessions', ['id' => 'other-device-session']);
        $this->assertAuthenticatedAs($user);
    }

    #[DataProvider('roles')]
    public function test_password_change_hashes_the_new_password_revokes_other_sessions_and_keeps_current_access(string $role): void
    {
        $user = User::factory()->create(['role' => $role, 'password' => self::CURRENT_PASSWORD]);
        $otherUser = User::factory()->create();
        $this->insertSession('other-device-one', $user);
        $this->insertSession('other-device-two', $user);
        $this->insertSession('unrelated-session', $otherUser);
        $this->actingAs($user)->withSession(['_token' => 'token-before-password-change']);
        $oldSessionId = session()->getId();

        $this->put(route('profile.password.update'), [
            'current_password' => self::CURRENT_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertFalse(Hash::check(self::CURRENT_PASSWORD, $user->password));
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertNotSame('token-before-password-change', session()->token());
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device-one']);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device-two']);
        $this->assertDatabaseHas('sessions', ['id' => 'unrelated-session']);
        $this->get(route('profile.edit'))->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_session_with_a_stale_password_hash_is_rejected_after_password_change(): void
    {
        $user = User::factory()->create(['password' => self::CURRENT_PASSWORD]);
        $oldSessionHash = auth()->guard()->hashPasswordForCookie($user->password);
        $this->actingAs($user)->put(route('profile.password.update'), [
            'current_password' => self::CURRENT_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->withSession(['password_hash_web' => $oldSessionHash]);

        $this->get(route('profile.edit'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[DataProvider('invalidPasswords')]
    public function test_new_password_must_meet_length_byte_limit_and_confirmation_rules(string $password, string $confirmation): void
    {
        $user = User::factory()->create(['password' => self::CURRENT_PASSWORD]);
        $originalHash = $user->password;

        $this->actingAs($user)->put(route('profile.password.update'), [
            'current_password' => self::CURRENT_PASSWORD,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ])->assertSessionHasErrors('password');

        $this->assertSame($originalHash, $user->fresh()->password);
    }

    public static function invalidPasswords(): array
    {
        return [
            'too short' => ['short', 'short'],
            'beyond byte limit' => [str_repeat('א', 37), str_repeat('א', 37)],
            'confirmation mismatch' => [self::NEW_PASSWORD, 'Different-password-83'],
        ];
    }

    public function test_profile_service_rechecks_an_already_deactivated_actor(): void
    {
        $user = User::factory()->create();
        User::query()->whereKey($user->id)->update(['is_active' => false]);

        $this->expectException(AuthorizationException::class);

        app(ProfileService::class)->update($user, $this->details());
    }

    private function details(array $overrides = []): array
    {
        return array_replace([
            'first_name' => 'שם מעודכן',
            'last_name' => 'משפחה',
            'gender' => 'female',
            'birth_date' => '2000-02-29',
        ], $overrides);
    }

    private function insertSession(string $id, User $user): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'payload' => base64_encode(serialize([])),
            'last_activity' => time(),
        ]);
    }

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previousErrorMode = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorMode);

        return new DOMXPath($document);
    }
}
