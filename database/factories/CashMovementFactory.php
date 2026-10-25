<?php

namespace Database\Factories;

use App\Enums\CashMovementType;
use App\Models\Account;
use App\Models\CashMovement;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashMovement>
 */
class CashMovementFactory extends Factory
{
    /**
     * Define the model's default state: a deposit in 2026.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = fake()->dateTimeBetween('2026-01-05', '2026-06-30')->format('Y-m-d');

        return [
            'account_id' => Account::factory(),
            'movement_type' => CashMovementType::Deposit,
            'movement_date' => $date,
            'settlement_date' => $date,
            'amount' => Money::fromCents(fake()->numberBetween(10_000, 1_000_000)),
        ];
    }
}
