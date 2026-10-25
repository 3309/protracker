<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Enums\Broker;
use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'broker' => fake()->randomElement(Broker::cases()),
            'nickname' => fake()->unique()->words(2, true),
            'account_type' => fake()->randomElement(AccountType::cases()),
            'account_number_last4' => fake()->numerify('####'),
        ];
    }

    /**
     * Indicate that the account has been archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'archived_at' => now(),
        ]);
    }
}
