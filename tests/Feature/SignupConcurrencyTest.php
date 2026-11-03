<?php

namespace Tests\Feature;

use App\Models\PendingSignupRequest;
use App\Models\SignupSetting;
use App\Models\User;
use App\Services\AccountManagementService;
use App\Services\SignupService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConcurrentProcesses;
use Tests\TestCase;

class SignupConcurrencyTest extends TestCase
{
    // Each fork needs committed fixtures visible to its independent connection.
    use DatabaseMigrations;

    private const PASSWORD = 'Applicant-chosen-password-93';

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('The MariaDB signup races require PHP pcntl and local socket pairs.');
        }
    }

    #[DataProvider('processOrders')]
    public function test_simultaneous_worker_and_manager_submissions_cannot_reserve_the_same_username_twice(bool $reverseProcesses): void
    {
        $this->openSignup('worker');
        $this->openSignup('manager');
        $attributes = $this->applicantAttributes('same.applicant');
        $operations = [
            'worker' => function () use ($attributes): string {
                return $this->submitOutcome('worker', $attributes);
            },
            'manager' => function () use ($attributes): string {
                return $this->submitOutcome('manager', $attributes);
            },
        ];

        $results = ConcurrentProcesses::run($reverseProcesses ? array_reverse($operations, true) : $operations);

        $outcomes = array_values($results);
        sort($outcomes);
        $this->assertSame(['accepted', 'duplicate'], $outcomes);
        $pending = PendingSignupRequest::query()->sole();
        $this->assertSame($results['worker'] === 'accepted' ? 'worker' : 'manager', $pending->role);
        $this->assertTrue(Hash::check(self::PASSWORD, $pending->password));
        $this->assertDatabaseCount('users', 0);
    }

    #[DataProvider('processOrders')]
    public function test_closing_signup_racing_a_submission_always_blocks_later_submissions_and_preserves_committed_requests(bool $reverseProcesses): void
    {
        $this->openSignup('worker');
        $manager = User::factory()->manager()->create();
        $existing = $this->pendingRequest('already.pending');
        $attributes = $this->applicantAttributes('racing.closure');
        $operations = [
            'submit' => function () use ($attributes): string {
                return $this->submitOutcome('worker', $attributes);
            },
            'close' => function () use ($manager): string {
                $this->pauseAfterAccountGate();
                app(SignupService::class)->setEnabled($manager, 'worker', false);

                return 'closed';
            },
        ];

        $results = ConcurrentProcesses::run($reverseProcesses ? array_reverse($operations, true) : $operations);

        $this->assertSame('closed', $results['close']);
        $this->assertContains($results['submit'], ['accepted', 'closed']);
        $this->assertFalse(SignupSetting::query()->findOrFail('worker')->is_enabled);
        $this->assertDatabaseHas('pending_signup_requests', ['id' => $existing->id]);
        $this->assertSame($results['submit'] === 'accepted', PendingSignupRequest::where('username', $attributes['username'])->exists());
        $this->assertFalse(app(SignupService::class)->submit('worker', $this->applicantAttributes('after.closure')));
        $this->assertDatabaseMissing('pending_signup_requests', ['username' => 'after.closure']);
        $this->assertDatabaseCount('users', 1);
    }

    #[DataProvider('processOrders')]
    public function test_two_managers_approving_the_same_snapshot_create_each_fixed_role_exactly_once(bool $reverseProcesses): void
    {
        $firstManager = User::factory()->manager()->create();
        $secondManager = User::factory()->manager()->create();
        $worker = $this->pendingRequest('once.worker');
        $manager = $this->pendingRequest('once.manager', 'manager');
        $firstReview = app(SignupService::class)->review($firstManager, 'approve');
        $secondReview = app(SignupService::class)->review($secondManager, 'approve');
        $operations = [
            'first' => function () use ($firstManager, $firstReview): string {
                return $this->processOutcome($firstManager, $firstReview['token']);
            },
            'second' => function () use ($secondManager, $secondReview): string {
                return $this->processOutcome($secondManager, $secondReview['token']);
            },
        ];

        $results = ConcurrentProcesses::run($reverseProcesses ? array_reverse($operations, true) : $operations);

        $this->assertSame('processed', $results['first']);
        $this->assertSame('processed', $results['second']);
        $this->assertDatabaseCount('users', 4);
        $this->assertDatabaseCount('pending_signup_requests', 0);
        $createdWorker = User::where('username', $worker->username)->sole();
        $createdManager = User::where('username', $manager->username)->sole();
        $this->assertSame('worker', $createdWorker->role);
        $this->assertSame('manager', $createdManager->role);
        $this->assertSame($worker->password, $createdWorker->password);
        $this->assertSame($manager->password, $createdManager->password);
    }

    #[DataProvider('processOrders')]
    public function test_approval_racing_deletion_never_deletes_an_approved_account_or_recreates_a_deleted_request(bool $reverseProcesses): void
    {
        $approver = User::factory()->manager()->create();
        $deleter = User::factory()->manager()->create();
        $pending = $this->pendingRequest('approve.or.delete', 'manager');
        $approveReview = app(SignupService::class)->review($approver, 'approve');
        $deleteReview = app(SignupService::class)->review($deleter, 'delete');
        $operations = [
            'approve' => function () use ($approver, $approveReview): string {
                return $this->processOutcome($approver, $approveReview['token']);
            },
            'delete' => function () use ($deleter, $deleteReview): string {
                return $this->processOutcome($deleter, $deleteReview['token']);
            },
        ];

        $results = ConcurrentProcesses::run($reverseProcesses ? array_reverse($operations, true) : $operations);

        $this->assertSame('processed', $results['approve']);
        $this->assertSame('processed', $results['delete']);
        $this->assertDatabaseCount('pending_signup_requests', 0);
        $created = User::where('username', $pending->username)->first();
        if ($created !== null) {
            $this->assertSame('manager', $created->role);
            $this->assertSame($pending->password, $created->password);
            $this->assertTrue($created->is_active);
        }
        $this->assertDatabaseHas('users', ['id' => $approver->id, 'is_active' => true]);
        $this->assertDatabaseHas('users', ['id' => $deleter->id, 'is_active' => true]);
        $this->assertSame($created === null ? 2 : 3, User::query()->count());
    }

    #[DataProvider('processOrders')]
    public function test_pending_manager_approval_racing_manual_worker_creation_preserves_the_account_created_first(bool $reverseProcesses): void
    {
        $manager = User::factory()->manager()->create();
        $otherManager = User::factory()->manager()->create();
        $pending = $this->pendingRequest('same.account', 'manager');
        $review = app(SignupService::class)->review($manager, 'approve');
        $manualAttributes = array_replace($this->applicantAttributes($pending->username), [
            'role' => 'worker', 'password' => 'Manually-created-password-84',
        ]);
        $operations = [
            'approve' => function () use ($manager, $review): string {
                return $this->processOutcome($manager, $review['token']);
            },
            'create' => function () use ($otherManager, $manualAttributes): string {
                $this->pauseAfterAccountGate();
                try {
                    app(AccountManagementService::class)->create($otherManager, $manualAttributes);

                    return 'created';
                } catch (ValidationException) {
                    return 'duplicate';
                }
            },
        ];

        $results = ConcurrentProcesses::run($reverseProcesses ? array_reverse($operations, true) : $operations);

        $this->assertSame('processed', $results['approve']);
        $account = User::where('username', $pending->username)->sole();
        if ($results['create'] === 'created') {
            $this->assertSame('worker', $account->role);
            $this->assertTrue(Hash::check($manualAttributes['password'], $account->password));
            $this->assertNotEmpty($pending->fresh()->review_error);
        } else {
            $this->assertSame('duplicate', $results['create']);
            $this->assertSame('manager', $account->role);
            $this->assertSame($pending->password, $account->password);
            $this->assertDatabaseMissing('pending_signup_requests', ['id' => $pending->id]);
        }
        $this->assertDatabaseCount('users', 3);
    }

    #[DataProvider('processOrders')]
    public function test_pending_manager_approval_racing_worker_import_cannot_overwrite_the_winning_role_or_password(bool $reverseProcesses): void
    {
        $manager = User::factory()->manager()->create();
        $pending = $this->pendingRequest('import.or.approve', 'manager');
        $review = app(SignupService::class)->review($manager, 'approve');
        $importAttributes = array_replace($this->applicantAttributes($pending->username), [
            'role' => 'worker', 'password' => 'Imported-worker-password-84',
        ]);
        $operations = [
            'approve' => function () use ($manager, $review): string {
                return $this->processOutcome($manager, $review['token']);
            },
            'import' => function () use ($importAttributes): string {
                $this->pauseAfterAccountGate();
                $result = app(AccountManagementService::class)->importWorkers([$importAttributes]);

                return $result['created'] === 1 ? 'created' : 'skipped';
            },
        ];

        $results = ConcurrentProcesses::run($reverseProcesses ? array_reverse($operations, true) : $operations);

        $this->assertSame('processed', $results['approve']);
        $account = User::where('username', $pending->username)->sole();
        if ($results['import'] === 'created') {
            $this->assertSame('worker', $account->role);
            $this->assertTrue(Hash::check($importAttributes['password'], $account->password));
            $this->assertNotEmpty($pending->fresh()->review_error);
        } else {
            $this->assertSame('skipped', $results['import']);
            $this->assertSame('manager', $account->role);
            $this->assertSame($pending->password, $account->password);
            $this->assertDatabaseMissing('pending_signup_requests', ['id' => $pending->id]);
        }
        $this->assertTrue($account->is_active);
        $this->assertDatabaseCount('users', 2);
    }

    #[DataProvider('processOrders')]
    public function test_revoking_a_manager_racing_their_approval_rechecks_authority_inside_the_transaction(bool $reverseProcesses): void
    {
        $manager = User::factory()->manager()->create();
        $otherManager = User::factory()->manager()->create();
        $pending = $this->pendingRequest('revoked.approver');
        $review = app(SignupService::class)->review($manager, 'approve');
        $operations = [
            'approve' => function () use ($manager, $review): string {
                try {
                    return $this->processOutcome($manager, $review['token']);
                } catch (AuthorizationException) {
                    return 'revoked';
                }
            },
            'revoke' => function () use ($otherManager, $manager): string {
                $this->pauseAfterAccountGate();
                app(AccountManagementService::class)->resetPassword($otherManager, $manager, 'Manager-reset-password-84');

                return 'revoked';
            },
        ];

        $results = ConcurrentProcesses::run($reverseProcesses ? array_reverse($operations, true) : $operations);

        $this->assertSame('revoked', $results['revoke']);
        $this->assertContains($results['approve'], ['processed', 'revoked']);
        $this->assertSame(1, $manager->fresh()->session_version);
        $this->assertSame($results['approve'] === 'processed', User::where('username', $pending->username)->exists());
        $this->assertSame($results['approve'] === 'revoked', PendingSignupRequest::whereKey($pending->id)->exists());
    }

    public static function processOrders(): array
    {
        return ['original process order' => [false], 'reversed process order' => [true]];
    }

    /** Service inputs have already passed public normalization and validation. */
    private function applicantAttributes(string $username): array
    {
        return [
            'username' => $username, 'first_name' => 'דנה', 'last_name' => 'כהן',
            'gender' => 'female', 'birth_date' => null, 'password' => self::PASSWORD,
        ];
    }

    private function openSignup(string $role): void
    {
        SignupSetting::query()->updateOrCreate(['role' => $role], ['is_enabled' => true]);
    }

    private function pendingRequest(string $username, string $role = 'worker'): PendingSignupRequest
    {
        return PendingSignupRequest::query()->create(array_replace($this->applicantAttributes($username), [
            'role' => $role, 'password' => Hash::make(self::PASSWORD),
        ]));
    }

    /** Only expected duplicate validation is converted to a business outcome. */
    private function submitOutcome(string $role, array $attributes): string
    {
        $this->pauseAfterAccountGate();
        try {
            return app(SignupService::class)->submit($role, $attributes) ? 'accepted' : 'closed';
        } catch (ValidationException) {
            return 'duplicate';
        }
    }

    private function processOutcome(User $manager, string $token): string
    {
        $this->pauseAfterAccountGate();
        app(SignupService::class)->process($manager, $token);

        return 'processed';
    }

    /** Widen real lock contention rather than inserting a production test hook. */
    private function pauseAfterAccountGate(): void
    {
        $paused = false;
        DB::listen(function (QueryExecuted $query) use (&$paused): void {
            if (! $paused && str_contains(strtolower($query->sql), 'get_lock(')) {
                $paused = true;
                usleep(150_000);
            }
        });
    }
}
