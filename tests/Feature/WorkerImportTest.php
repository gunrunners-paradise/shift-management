<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WorkerImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['username', 'first_name', 'last_name', 'gender', 'birth_date', 'password'];

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_two_hebrew_workers_are_active_with_usable_hashed_passwords(): void
    {
        $first = $this->workerAttributes();
        $second = $this->workerAttributes([
            'username' => 'sarah.levi', 'first_name' => 'שרה', 'last_name' => 'לוי',
            'gender' => 'female', 'birth_date' => '1998-07-25', 'password' => 'Sarah-test-password-84',
        ]);
        $path = $this->writeCsv([$first, $second]);

        [$status, $output] = $this->runImport($path);

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('Workers created: 2', $output);
        $this->assertDatabaseCount('users', 2);

        foreach ([$first, $second] as $attributes) {
            $worker = User::where('username', $attributes['username'])->firstOrFail();
            $this->assertSame($attributes['first_name'], $worker->first_name);
            $this->assertSame($attributes['last_name'], $worker->last_name);
            $this->assertSame($attributes['gender'], $worker->gender);
            $this->assertSame($attributes['birth_date'], $worker->birth_date->toDateString());
            $this->assertSame('worker', $worker->role);
            $this->assertTrue($worker->is_active);
            $this->assertNull($worker->photo_path);
            $this->assertNotSame($attributes['password'], $worker->password);
            $this->assertTrue(Hash::check($attributes['password'], $worker->password));
            $this->assertStringNotContainsString($attributes['password'], $output);
        }

        $this->post(route('login.store'), [
            'username' => $first['username'], 'password' => $first['password'],
        ])->assertRedirect(route('worker.home'));
        $this->assertAuthenticatedAs(User::where('username', $first['username'])->firstOrFail());
    }

    public function test_birth_date_column_can_be_omitted_and_worker_can_fill_it_in_later(): void
    {
        $headers = ['username', 'first_name', 'last_name', 'gender', 'password'];
        $path = $this->writeCsv([$this->workerAttributes()], $headers);

        [$status, $output] = $this->runImport($path);

        $this->assertSame(0, $status, $output);
        $worker = User::query()->sole();
        $this->assertNull($worker->birth_date);

        $this->actingAs($worker)->patch(route('profile.update'), [
            'first_name' => $worker->first_name, 'last_name' => $worker->last_name,
            'gender' => $worker->gender, 'birth_date' => '1995-04-12',
        ])->assertSessionHasNoErrors();

        $this->assertSame('1995-04-12', $worker->fresh()->birth_date->toDateString());
    }

    public function test_blank_birth_date_is_stored_as_null(): void
    {
        $path = $this->writeCsv([$this->workerAttributes(['birth_date' => ''])]);

        [$status, $output] = $this->runImport($path);

        $this->assertSame(0, $status, $output);
        $this->assertNull(User::query()->sole()->birth_date);
    }

    public function test_bom_windows_line_endings_quoted_commas_and_blank_lines_are_supported(): void
    {
        $password = '  Chosen,"test"-password-93  ';
        $attributes = $this->workerAttributes([
            'username' => '  DAVID.COHEN  ',
            'first_name' => 'דוד, אור', 'last_name' => 'כהן "לוי"', 'password' => $password,
        ]);
        $csv = $this->csvContents([$attributes], self::HEADERS, "\r\n");
        $path = $this->writeContents("\xEF\xBB\xBF\r\n".$csv."\r\n\r\n");

        [$status, $output] = $this->runImport($path);

        $this->assertSame(0, $status, $output);
        $worker = User::query()->sole();
        $this->assertSame('david.cohen', $worker->username);
        $this->assertSame('דוד, אור', $worker->first_name);
        $this->assertSame('כהן "לוי"', $worker->last_name);
        // Password whitespace and punctuation are credentials, not CSV padding.
        $this->assertTrue(Hash::check($password, $worker->password));
        $this->assertFalse(Hash::check(trim($password), $worker->password));
        $this->assertStringNotContainsString($password, $output);
    }

    public function test_columns_are_mapped_by_name_when_header_order_changes(): void
    {
        $headers = ['password', 'last_name', 'gender', 'username', 'birth_date', 'first_name'];
        $attributes = $this->workerAttributes();
        $path = $this->writeCsv([$attributes], $headers);

        [$status, $output] = $this->runImport($path);

        $this->assertSame(0, $status, $output);
        $worker = User::query()->sole();
        $this->assertSame($attributes['username'], $worker->username);
        $this->assertSame($attributes['first_name'], $worker->first_name);
        $this->assertTrue(Hash::check($attributes['password'], $worker->password));
    }

    public function test_bom_before_a_quoted_first_header_is_supported(): void
    {
        $csv = $this->csvContents([$this->workerAttributes()]);
        $csv = '"username"'.substr($csv, strlen('username'));
        $path = $this->writeContents("\xEF\xBB\xBF".$csv);

        [$status, $output] = $this->runImport($path);

        $this->assertSame(0, $status, $output);
        $this->assertDatabaseHas('users', ['username' => 'david.cohen']);
    }

    public function test_unicode_whitespace_is_trimmed_like_existing_account_forms(): void
    {
        $nonbreakingSpace = "\u{00A0}";
        $path = $this->writeCsv([$this->workerAttributes([
            'username' => $nonbreakingSpace.'DAVID.COHEN'.$nonbreakingSpace,
            'first_name' => $nonbreakingSpace.'דוד'.$nonbreakingSpace,
            'last_name' => $nonbreakingSpace.'כהן'.$nonbreakingSpace,
            'gender' => $nonbreakingSpace.'male'.$nonbreakingSpace,
            'birth_date' => $nonbreakingSpace.'1995-04-12'.$nonbreakingSpace,
        ])]);

        [$status, $output] = $this->runImport($path);

        $this->assertSame(0, $status, $output);
        $worker = User::query()->sole();
        $this->assertSame('david.cohen', $worker->username);
        $this->assertSame('דוד', $worker->first_name);
        $this->assertSame('כהן', $worker->last_name);
        $this->assertSame('male', $worker->gender);
        $this->assertSame('1995-04-12', $worker->birth_date->toDateString());
    }

    #[DataProvider('supportedGenders')]
    public function test_existing_gender_values_are_supported(string $gender): void
    {
        $path = $this->writeCsv([$this->workerAttributes(['gender' => $gender])]);

        [$status, $output] = $this->runImport($path);

        $this->assertSame(0, $status, $output);
        $this->assertSame($gender, User::query()->sole()->gender);
    }

    public static function supportedGenders(): array
    {
        return [['female'], ['male'], ['other']];
    }

    #[DataProvider('invalidWorkerFields')]
    public function test_invalid_input_reports_its_row_and_prevents_the_entire_batch(string $field, string $value): void
    {
        $path = $this->writeCsv([
            $this->workerAttributes(),
            $this->workerAttributes(['username' => 'invalid.worker', $field => $value]),
        ]);

        [$status, $output] = $this->runImport($path);

        $this->assertNotSame(0, $status, $output);
        $this->assertStringContainsString('Row 3', $output);
        $this->assertDatabaseCount('users', 0);

        if ($field === 'password' && $value !== '') {
            $this->assertStringNotContainsString($value, $output);
        }
    }

    public static function invalidWorkerFields(): array
    {
        return [
            'missing username' => ['username', ''],
            'short username' => ['username', 'ab'],
            'long username' => ['username', str_repeat('a', 61)],
            'unsupported username characters' => ['username', 'עובד.חדש'],
            'spaces within username' => ['username', 'two names'],
            'missing first name' => ['first_name', ''],
            'unicode whitespace only first name' => ['first_name', "\u{00A0}"],
            'too long first name' => ['first_name', str_repeat('א', 61)],
            'missing last name' => ['last_name', ''],
            'unicode whitespace only last name' => ['last_name', "\u{00A0}"],
            'too long last name' => ['last_name', str_repeat('ב', 61)],
            'missing gender' => ['gender', ''],
            'unsupported gender' => ['gender', 'unknown'],
            'impossible birthday' => ['birth_date', '2001-02-29'],
            'wrong birthday format' => ['birth_date', '12/04/1995'],
            'future birthday' => ['birth_date', '2999-01-01'],
            'missing password' => ['password', ''],
            'eleven character password' => ['password', 'short-12345'],
            'password over character limit' => ['password', str_repeat('x', 73)],
            'password over bcrypt byte limit' => ['password', str_repeat('א', 37)],
        ];
    }

    public function test_invalid_existing_username_row_also_prevents_new_accounts(): void
    {
        $existing = User::factory()->create(['username' => 'existing.worker']);
        $original = $existing->fresh()->getRawOriginal();
        $path = $this->writeCsv([
            $this->workerAttributes(),
            $this->workerAttributes(['username' => $existing->username, 'password' => 'short']),
        ]);

        [$status, $output] = $this->runImport($path);

        $this->assertNotSame(0, $status, $output);
        $this->assertStringContainsString('Row 3', $output);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($original, $existing->fresh()->getRawOriginal());
    }

    public function test_existing_workers_and_managers_are_skipped_without_any_changes(): void
    {
        $existingWorker = User::factory()->inactive()->create(['username' => 'existing.worker']);
        $existingManager = User::factory()->manager()->create(['username' => 'existing.manager']);
        $originalWorker = $existingWorker->fresh()->getRawOriginal();
        $originalManager = $existingManager->fresh()->getRawOriginal();
        $path = $this->writeCsv([
            $this->workerAttributes(['username' => '  EXISTING.WORKER  ']),
            $this->workerAttributes(['username' => 'EXISTING.MANAGER']),
            $this->workerAttributes(),
        ]);

        [$status, $output] = $this->runImport($path);

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('existing.worker', $output);
        $this->assertStringContainsString('existing.manager', $output);
        $this->assertStringContainsString('Existing usernames skipped: 2', $output);
        $this->assertStringContainsString('Workers created: 1', $output);
        $this->assertDatabaseCount('users', 3);
        $this->assertSame($originalWorker, $existingWorker->fresh()->getRawOriginal());
        $this->assertSame($originalManager, $existingManager->fresh()->getRawOriginal());
    }

    public function test_case_and_whitespace_normalized_duplicates_within_csv_are_fatal(): void
    {
        $path = $this->writeCsv([
            $this->workerAttributes(['username' => 'same.worker']),
            $this->workerAttributes(['username' => '  SAME.WORKER  ']),
        ]);

        [$status, $output] = $this->runImport($path);

        $this->assertNotSame(0, $status, $output);
        $this->assertStringContainsString('Row 3', $output);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_dry_run_reports_new_and_existing_workers_without_database_writes(): void
    {
        $existing = User::factory()->inactive()->create(['username' => 'existing.worker']);
        $original = $existing->fresh()->getRawOriginal();
        $path = $this->writeCsv([
            $this->workerAttributes(),
            $this->workerAttributes(['username' => 'EXISTING.WORKER']),
        ]);
        $queries = [];
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        $connection->setEventDispatcher(clone $dispatcher);
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        Hash::partialMock()->shouldNotReceive('make');

        try {
            [$status, $output] = $this->runImport($path, true);
        } finally {
            $connection->setEventDispatcher($dispatcher);
        }

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('New workers ready to import: 1', $output);
        $this->assertStringContainsString('Existing usernames skipped: 1', $output);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($original, $existing->fresh()->getRawOriginal());
        $this->assertNotEmpty($queries);

        foreach ($queries as $sql) {
            $this->assertStringStartsWith('select', strtolower(ltrim($sql)));
            $this->assertStringNotContainsString('get_lock', strtolower($sql));
        }
    }

    public function test_invalid_dry_run_fails_without_creating_accounts(): void
    {
        $path = $this->writeCsv([
            $this->workerAttributes(),
            $this->workerAttributes(['username' => 'invalid.worker', 'gender' => 'unknown']),
        ]);

        [$status, $output] = $this->runImport($path, true);

        $this->assertNotSame(0, $status, $output);
        $this->assertStringContainsString('Row 3', $output);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_repeating_an_import_preserves_account_identity_and_password_hash(): void
    {
        $path = $this->writeCsv([$this->workerAttributes()]);
        [$firstStatus, $firstOutput] = $this->runImport($path);
        $this->assertSame(0, $firstStatus, $firstOutput);
        $original = User::query()->sole()->getRawOriginal();

        [$status, $output] = $this->runImport($path);

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('Workers created: 0', $output);
        $this->assertStringContainsString('Existing usernames skipped: 1', $output);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($original, User::query()->sole()->getRawOriginal());
    }

    #[DataProvider('malformedRecords')]
    public function test_malformed_csv_is_rejected_without_importing_earlier_valid_rows(string $malformedRow): void
    {
        $validCsv = $this->csvContents([$this->workerAttributes()]);
        $path = $this->writeContents($validCsv.$malformedRow."\n");

        [$status, $output] = $this->runImport($path);

        $this->assertNotSame(0, $status, $output);
        $this->assertStringContainsString('Row 3', $output);
        $this->assertDatabaseCount('users', 0);
    }

    public static function malformedRecords(): array
    {
        return [
            'too few fields' => ['second.worker,דנה,כהן,female,2000-01-01'],
            'too many fields' => ['second.worker,דנה,כהן,female,2000-01-01,Chosen-test-password-93,unexpected'],
            'unclosed quoted field' => ['second.worker,"דנה,כהן,female,2000-01-01,Chosen-test-password-93'],
            'quote inside unquoted field' => ['second.worker,ד"נה,כהן,female,2000-01-01,Chosen-test-password-93'],
            'text after closing quote' => ['second.worker,"דנה"junk,כהן,female,2000-01-01,Chosen-test-password-93'],
            'delimiter-only nonempty row' => [',,,,,'],
        ];
    }

    public function test_errors_after_quoted_multiline_fields_use_physical_line_numbers(): void
    {
        $path = $this->writeCsv([
            $this->workerAttributes(['first_name' => "דוד\nאור"]),
            $this->workerAttributes(['username' => 'invalid.worker', 'gender' => 'unknown']),
        ]);

        [$status, $output] = $this->runImport($path);

        $this->assertNotSame(0, $status, $output);
        $this->assertStringContainsString('Row 4', $output);
        $this->assertStringNotContainsString('Row 3', $output);
        $this->assertDatabaseCount('users', 0);
    }

    #[DataProvider('invalidHeaders')]
    public function test_missing_unexpected_or_duplicate_headers_are_rejected(array $headers): void
    {
        $path = $this->writeCsv([$this->workerAttributes()], $headers);

        [$status, $output] = $this->runImport($path);

        $this->assertNotSame(0, $status, $output);
        $this->assertStringContainsString('Row 1', $output);
        $this->assertDatabaseCount('users', 0);
    }

    public static function invalidHeaders(): array
    {
        return [
            'missing password' => [['username', 'first_name', 'last_name', 'gender', 'birth_date']],
            'role selection is not supported' => [[...self::HEADERS, 'role']],
            'activation selection is not supported' => [[...self::HEADERS, 'is_active']],
            'duplicate username header' => [[...self::HEADERS, 'username']],
            'unknown column' => [[...self::HEADERS, 'extra']],
            'empty header' => [[...self::HEADERS, '']],
        ];
    }

    #[DataProvider('malformedInitialHeaders')]
    public function test_malformed_first_header_is_reported_at_row_one_without_treating_data_as_headers(string $header): void
    {
        $csv = $this->csvContents([$this->workerAttributes()]);
        $body = substr($csv, strpos($csv, "\n") + 1);
        $path = $this->writeContents($header."\n".$body);

        [$status, $output] = $this->runImport($path);

        $this->assertNotSame(0, $status, $output);
        $this->assertStringContainsString('Row 1', $output);
        $this->assertStringNotContainsString('Row 2', $output);
        $this->assertDatabaseCount('users', 0);
    }

    public static function malformedInitialHeaders(): array
    {
        return [
            'stray quote in header' => ['user"name,first_name,last_name,gender,birth_date,password'],
            'invalid UTF8 in header' => ["user\xC3\x28name,first_name,last_name,gender,birth_date,password"],
            'NUL in header' => ["user\0name,first_name,last_name,gender,birth_date,password"],
        ];
    }

    public function test_invalid_utf8_is_rejected_without_echoing_the_csv_record(): void
    {
        $password = 'Secret-UTF8-test-password-93';
        $csv = $this->csvContents([$this->workerAttributes(['password' => $password])]);
        $path = $this->writeContents(str_replace('דוד', "\xC3\x28", $csv));

        [$status, $output] = $this->runImport($path);

        $this->assertNotSame(0, $status, $output);
        $this->assertStringContainsString('Row 2', $output);
        $this->assertStringNotContainsString($password, $output);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_validation_and_header_errors_never_echo_untrusted_field_values(): void
    {
        $usernameValue = 'Never-display-this-username!';
        $dateValue = 'Never-display-this-date';
        $password = 'Secret-93';
        $path = $this->writeCsv([
            $this->workerAttributes(['username' => $usernameValue, 'password' => $password]),
            $this->workerAttributes(['username' => 'second.worker', 'birth_date' => $dateValue]),
        ]);

        [$status, $output] = $this->runImport($path);

        $this->assertNotSame(0, $status, $output);
        $this->assertStringContainsString('Row 2', $output);
        $this->assertStringContainsString('Row 3', $output);
        $this->assertStringNotContainsString($usernameValue, $output);
        $this->assertStringNotContainsString($dateValue, $output);
        $this->assertStringNotContainsString($password, $output);

        $headerValue = 'Never-display-this-header-password-93';
        $headerPath = $this->writeCsv([$this->workerAttributes()], [...self::HEADERS, $headerValue]);
        [$headerStatus, $headerOutput] = $this->runImport($headerPath);

        $this->assertNotSame(0, $headerStatus, $headerOutput);
        $this->assertStringNotContainsString($headerValue, $headerOutput);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_empty_file_is_rejected(): void
    {
        $path = $this->writeContents('');

        [$status, $output] = $this->runImport($path);

        $this->assertNotSame(0, $status, $output);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_missing_file_is_rejected_with_a_failure_exit_code(): void
    {
        [$status, $output] = $this->runImport(sys_get_temp_dir().'/missing-worker-import-'.bin2hex(random_bytes(8)).'.csv');

        $this->assertNotSame(0, $status, $output);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_relative_paths_are_resolved_from_the_project_directory(): void
    {
        $relativePath = 'storage/app/private/worker-import-test-'.bin2hex(random_bytes(8)).'.csv';
        $path = base_path($relativePath);
        $this->temporaryFiles[] = $path;
        file_put_contents($path, $this->csvContents([$this->workerAttributes()]));
        chmod($path, 0600);

        [$status, $output] = $this->runImport($relativePath);

        $this->assertSame(0, $status, $output);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_database_failure_on_a_later_worker_rolls_back_the_whole_import(): void
    {
        $existing = User::factory()->create(['username' => 'retained.worker']);
        $original = $existing->fresh()->getRawOriginal();
        $path = $this->writeCsv([
            $this->workerAttributes(),
            $this->workerAttributes(['username' => 'second.worker']),
        ]);
        $dispatcher = User::getEventDispatcher();
        User::setEventDispatcher(clone $dispatcher);
        User::creating(function (User $worker): void {
            if ($worker->username === 'second.worker') {
                // Force a real MariaDB constraint failure after the first INSERT.
                $worker->role = str_repeat('x', 11);
            }
        });

        try {
            [$status, $output] = $this->runImport($path);
        } finally {
            User::setEventDispatcher($dispatcher);
        }

        $this->assertNotSame(0, $status, $output);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($original, $existing->fresh()->getRawOriginal());
    }

    public function test_unexpected_exception_details_never_expose_passwords_in_output_or_logs(): void
    {
        $password = 'Never-reveal-this-test-password-93';
        $path = $this->writeCsv([$this->workerAttributes(['password' => $password])]);
        $dispatcher = User::getEventDispatcher();
        $originalLogger = Log::getFacadeRoot();
        $logRecords = new TestHandler;
        Log::swap(new Logger(new MonologLogger('worker-import-test', [$logRecords])));
        User::setEventDispatcher(clone $dispatcher);
        User::creating(function () use ($password): void {
            throw new RuntimeException('Synthetic exception with sensitive data: '.$password);
        });

        try {
            [$status, $output] = $this->runImport($path);
            $loggedDetails = var_export($logRecords->getRecords(), true);
        } finally {
            User::setEventDispatcher($dispatcher);
            Log::swap($originalLogger);
        }

        $this->assertNotSame(0, $status, $output);
        $this->assertStringNotContainsString($password, $output);
        $this->assertStringNotContainsString($password, $loggedDetails);
        $this->assertStringNotContainsString('Synthetic exception', $output);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_hash_looking_password_is_hashed_as_literal_input_and_can_be_used_to_sign_in(): void
    {
        $hash = Hash::make('Original-test-password-93');
        $path = $this->writeCsv([$this->workerAttributes(['password' => $hash])]);

        [$status, $output] = $this->runImport($path);

        $this->assertSame(0, $status, $output);
        $this->assertStringNotContainsString($hash, $output);
        $worker = User::query()->sole();
        $this->assertNotSame($hash, $worker->password);
        $this->assertTrue(Hash::check($hash, $worker->password));
        $this->assertFalse(Hash::check('Original-test-password-93', $worker->password));

        $this->post(route('login.store'), [
            'username' => $worker->username, 'password' => $hash,
        ])->assertRedirect(route('worker.home'));
        $this->assertAuthenticatedAs($worker);
    }

    /** Every test varies only the attributes relevant to its own scenario. */
    private function workerAttributes(array $overrides = []): array
    {
        return array_replace([
            'username' => 'david.cohen', 'first_name' => 'דוד', 'last_name' => 'כהן',
            'gender' => 'male', 'birth_date' => '1995-04-12', 'password' => 'David-test-password-93',
        ], $overrides);
    }

    /** @return array{int, string} */
    private function runImport(string $path, bool $dryRun = false): array
    {
        $status = Artisan::call('app:import-workers', ['path' => $path, '--dry-run' => $dryRun]);

        return [$status, Artisan::output()];
    }

    private function writeCsv(array $workers, array $headers = self::HEADERS): string
    {
        return $this->writeContents($this->csvContents($workers, $headers));
    }

    /** Write passwords only to private temporary files, removed after each test. */
    private function writeContents(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'worker-import-test-');
        $this->temporaryFiles[] = $path;
        chmod($path, 0600);
        file_put_contents($path, $contents);

        return $path;
    }

    private function csvContents(array $workers, array $headers = self::HEADERS, string $lineEnding = "\n"): string
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $headers, ',', '"', '', $lineEnding);

        foreach ($workers as $attributes) {
            $fields = [];

            foreach ($headers as $header) {
                $fields[] = $attributes[$header] ?? '';
            }

            fputcsv($stream, $fields, ',', '"', '', $lineEnding);
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return $contents;
    }
}
