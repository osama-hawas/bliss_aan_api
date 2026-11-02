<?php

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => UserType::Client,
            'name' => fake()->name('female'),
            'phone' => '+9665'.fake()->unique()->numerify('########'),
            'email' => null,
            'locale' => 'ar',
            'status' => UserStatus::Active,
        ];
    }

    public function client(): static
    {
        return $this->state(['type' => UserType::Client]);
    }

    public function provider(): static
    {
        return $this->state(['type' => UserType::Provider]);
    }

    public function admin(): static
    {
        return $this->state(['type' => UserType::Admin]);
    }

    public function suspended(string $reason = 'Policy violation'): static
    {
        return $this->state(['status' => UserStatus::Suspended, 'status_reason' => $reason]);
    }
}
