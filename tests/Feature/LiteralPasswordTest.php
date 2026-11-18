<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountManagementService;
use App\Services\ProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LiteralPasswordTest extends TestCase
{
    use RefreshDatabase;

    public static function passwordEntryPoints(): array
    {
        return [['create'], ['bootstrap'], ['profile']];
    }

    #[DataProvider('passwordEntryPoints')]
    public function test_hash_shaped_input_remains_the_literal_password(string $entryPoint): void
    {
        // This valid 60-character password happens to be a bcrypt hash of a
        // different, short string. That short string must never authenticate.
        $literalPassword = Hash::make('Short7');
        $attributes = [
            'username' => 'literal-password', 'first_name' => 'בדיקה',
            'last_name' => 'סיסמה', 'gender' => 'other', 'role' => 'worker',
            'password' => $literalPassword,
        ];

        if ($entryPoint === 'bootstrap') {
            $user = app(AccountManagementService::class)->bootstrap($attributes);
        } elseif ($entryPoint === 'create') {
            $manager = User::factory()->manager()->create();
            $user = app(AccountManagementService::class)->create($manager, $attributes);
        } else {
            $user = User::factory()->create(['password' => 'Original-password-17']);
            $user = app(ProfileService::class)->changePassword(
                $user, 'Original-password-17', $literalPassword, 'synthetic-session',
            );
        }

        $storedHash = $user->fresh()->password;
        $this->assertNotSame($literalPassword, $storedHash);
        $this->assertTrue(Hash::check($literalPassword, $storedHash));
        $this->assertFalse(Hash::check('Short7', $storedHash));
    }
}
