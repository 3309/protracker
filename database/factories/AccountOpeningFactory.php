<?php

namespace Database\Factories;

use App\Enums\OpeningBalanceSource;
use App\Models\Account;
use App\Models\AccountOpening;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountOpening>
 */
class AccountOpeningFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'as_of_date' => '2026-01-01',
            'cash_balance' => Money::fromCents(fake()->numberBetween(0, 5_000_000)),
            'cash_source' => OpeningBalanceSource::BrokerStatement,
        ];
    }

    /**
     * Indicate that no starting cash has been entered yet.
     */
    public function withoutCash(): static
    {
        return $this->state(fn (array $attributes) => [
            'cash_balance' => null,
            'cash_source' => null,
        ]);
    }

    /**
     * Indicate that the opening-balance setup has been marked complete.
     */
    public function complete(): static
    {
        return $this->afterCreating(fn (AccountOpening $opening) => $opening->markComplete());
    }
}
