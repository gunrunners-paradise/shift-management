<?php

namespace App\Console\Commands;

use App\Http\Requests\StoreUserRequest;
use App\Models\User;
use App\Services\AccountManagementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateFirstManager extends Command
{
    protected $signature = 'app:create-manager';

    protected $description = 'Interactively create the first manager without default credentials';

    /**
     * Read the password with terminal echo disabled, validate, and hash via User.
     * No password argument, terminal output, fixture, or log contains plaintext.
     */
    public function handle(AccountManagementService $accounts): int
    {
        $existingManager = User::where('role', 'manager')->orderBy('id')->first();

        if ($existingManager !== null) {
            $this->info('A manager already exists. No account or password was changed.');
            $this->showSignInInstructions($existingManager);

            return self::SUCCESS;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Run php artisan app:create-manager in an interactive terminal.');

            return self::FAILURE;
        }

        $attributes = [
            'username' => Str::lower(trim((string) $this->ask('Username'))),
            'first_name' => trim((string) $this->ask('First name')),
            'last_name' => trim((string) $this->ask('Last name')),
            'gender' => $this->choice('Gender', ['female', 'male', 'other'], 'other'),
            'role' => 'manager',
            // Refuse an unhidden fallback on terminals without secure input.
            'password' => $this->secret('Password', false),
            'password_confirmation' => $this->secret('Confirm password', false),
        ];

        $validator = Validator::make($attributes, (new StoreUserRequest)->rules());

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $manager = $accounts->bootstrap($validator->validated());

        if ($manager === null) {
            $this->info('A manager already exists. No account or password was changed.');
            $manager = User::where('role', 'manager')->orderBy('id')->firstOrFail();
        } else {
            $this->info('First manager created successfully.');
        }

        $this->showSignInInstructions($manager);

        return self::SUCCESS;
    }

    private function showSignInInstructions(User $manager): void
    {
        $this->line('Username: '.$manager->username);
        $this->line('Role: manager');
        $this->line('Login URL: '.rtrim(config('app.url'), '/').'/login');
        $this->line('Open the login URL and enter this username and the password you chose.');
        $this->line('Passwords are never printed. Additional accounts are created on the manager dashboard.');
    }
}
