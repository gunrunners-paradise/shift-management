<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->regexify('worker_[a-z]{12}'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'gender' => 'other',
            'birth_date' => null,
            'photo_path' => null,
            'role' => 'worker',
            'is_active' => true,
            // A factory must not become an accidental source of usable default credentials.
            'password' => Str::random(32),
            'remember_token' => null,
        ];
    }

    public function manager(): static
    {
        return $this->state(fn () => ['role' => 'manager']);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
