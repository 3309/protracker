<?php

namespace Database\Factories;

use App\Enums\OpeningBalanceSource;
use App\Enums\PositionOrigin;
use App\Models\Account;
use App\Models\StockLot;
use App\Support\Quantity;
use App\Support\UnitPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockLot>
 */
class StockLotFactory extends Factory
{
    /**
     * Define the model's default state: an opening-balance lot bought in 2025.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'ticker' => fake()->randomElement(['AAPL', 'MSFT', 'KO', 'XYZ', 'BRK.B']),
            'origin' => PositionOrigin::OpeningBalance,
            'acquired_on' => fake()->dateTimeBetween('2025-01-01', '2025-12-31')->format('Y-m-d'),
            'shares' => Quantity::fromInteger(fake()->numberBetween(1, 5) * 100),
            'cost_per_share' => UnitPrice::fromUnits(fake()->numberBetween(10_0000, 500_0000)),
            'source' => OpeningBalanceSource::BrokerStatement,
        ];
    }

    /**
     * Indicate that the lot has been voided.
     */
    public function voided(): static
    {
        return $this->state(fn (array $attributes) => [
            'voided_at' => now(),
        ]);
    }
}
