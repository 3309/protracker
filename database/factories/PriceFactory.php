<?php

namespace Database\Factories;

use App\Enums\OptionType;
use App\Enums\PriceInstrument;
use App\Models\Price;
use App\Support\UnitPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Price>
 */
class PriceFactory extends Factory
{
    /**
     * Define the model's default state: a stock price.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'instrument' => PriceInstrument::Stock,
            'ticker' => 'XYZ',
            'price' => UnitPrice::fromInteger(fake()->numberBetween(10, 300)),
            'as_of' => now(),
        ];
    }

    /**
     * Indicate that the price is for an option contract.
     */
    public function option(OptionType $type, string $strike, string $expiresOn): static
    {
        return $this->state(fn (array $attributes) => [
            'instrument' => PriceInstrument::Option,
            'option_type' => $type,
            'strike' => UnitPrice::fromDecimal($strike),
            'expires_on' => $expiresOn,
        ]);
    }
}
