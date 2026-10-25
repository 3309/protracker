<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Dividend;
use App\Support\DividendRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates an unconfirmed dividend. Use Dividend::confirm() to record the
 * actual amount.
 *
 * @extends Factory<Dividend>
 */
class DividendFactory extends Factory
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
            'ticker' => fake()->randomElement(['AAPL', 'MSFT', 'KO', 'XYZ']),
            'ex_date' => '2026-03-10',
            'pay_date' => '2026-03-31',
            'amount_per_share' => DividendRate::fromDecimal('0.50'),
        ];
    }
}
