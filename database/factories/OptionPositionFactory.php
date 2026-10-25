<?php

namespace Database\Factories;

use App\Enums\OpeningBalanceSource;
use App\Enums\OptionType;
use App\Enums\PositionOrigin;
use App\Models\Account;
use App\Models\OptionPosition;
use App\Support\Money;
use App\Support\UnitPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OptionPosition>
 */
class OptionPositionFactory extends Factory
{
    /**
     * Define the model's default state: a short option opened in December
     * 2025 that expires in 2026.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $contracts = fake()->numberBetween(1, 3);

        return [
            'account_id' => Account::factory(),
            'underlying' => fake()->randomElement(['AAPL', 'MSFT', 'KO', 'XYZ']),
            'option_type' => fake()->randomElement(OptionType::cases()),
            'strike' => UnitPrice::fromInteger(fake()->numberBetween(20, 300)),
            'expires_on' => fake()->dateTimeBetween('2026-01-02', '2026-03-31')->format('Y-m-d'),
            'opened_on' => fake()->dateTimeBetween('2025-12-01', '2025-12-31')->format('Y-m-d'),
            'contracts' => $contracts,
            'premium_per_share' => UnitPrice::fromUnits(fake()->numberBetween(1000, 50000)),
            'opening_fees' => Money::fromCents(67 * $contracts),
            'origin' => PositionOrigin::OpeningBalance,
            'source' => OpeningBalanceSource::BrokerStatement,
        ];
    }

    /**
     * Indicate that the position has been voided.
     */
    public function voided(): static
    {
        return $this->state(fn (array $attributes) => [
            'voided_at' => now(),
        ]);
    }
}
