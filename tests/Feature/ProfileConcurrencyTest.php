<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountManagementService;
use App\Services\ProfileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\Support\ConcurrentProcesses;
use Tests\TestCase;

class ProfileConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('The MariaDB races require PHP pcntl and local socket pairs.');
        }
    }

    public function test_two_simultaneous_password_changes_cannot_both_reuse_the_old_current_password(): void
    {
        $currentPassword = 'Current-test-password-93';
        $firstPassword = 'First-new-test-password-84';
        $secondPassword = 'Second-new-test-password-75';
        $user = User::factory()->create(['password' => $currentPassword]);

        $results = ConcurrentProcesses::run([
            'first-device' => function () use ($user, $currentPassword, $firstPassword): string {
                try {
                    app(ProfileService::class)->changePassword($user, $currentPassword, $firstPassword, 'first-device');

                    return 'changed';
                } catch (ValidationException) {
                    return 'rejected';
                }
            },
            'second-device' => function () use ($user, $currentPassword, $secondPassword): string {
                try {
                    app(ProfileService::class)->changePassword($user, $currentPassword, $secondPassword, 'second-device');

                    return 'changed';
                } catch (ValidationException) {
                    return 'rejected';
                }
            },
        ]);

        sort($results);
        $this->assertSame(['changed', 'rejected'], $results);
        $hash = $user->fresh()->password;
        $firstWon = Hash::check($firstPassword, $hash);
        $secondWon = Hash::check($secondPassword, $hash);
        $this->assertNotSame($firstWon, $secondWon);
        $this->assertFalse(Hash::check($currentPassword, $hash));
    }

    public function test_profile_update_racing_deactivation_never_reactivates_or_elevates_the_worker(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create(['first_name' => 'Original']);

        $results = ConcurrentProcesses::run([
            'profile' => function () use ($worker): string {
                try {
                    app(ProfileService::class)->update($worker, [
                        'first_name' => 'Updated',
                        'last_name' => 'Worker',
                        'gender' => 'other',
                        'birth_date' => null,
                    ]);

                    return 'updated';
                } catch (AuthorizationException) {
                    return 'rejected';
                }
            },
            'deactivate' => function () use ($manager, $worker): string {
                app(AccountManagementService::class)->deactivate($manager, $worker);

                return 'deactivated';
            },
        ]);

        $this->assertSame('deactivated', $results['deactivate']);
        $this->assertContains($results['profile'], ['updated', 'rejected']);
        $worker->refresh();
        $this->assertFalse($worker->is_active);
        $this->assertSame('worker', $worker->role);
        $this->assertSame($results['profile'] === 'updated' ? 'Updated' : 'Original', $worker->first_name);
    }
}
