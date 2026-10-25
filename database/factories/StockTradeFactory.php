<?php

namespace Database\Factories;

use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\StockTrade;
use App\Support\MarketCalendar;
use App\Support\Quantity;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates trade rows only. A buy made here has no lot and no allocations;
 * use the SaveStockTrade action when a test needs the full stock engine.
 *
 * @extends Factory<StockTrade>
 */
class StockTradeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tradeDate = CarbonImmutable::parse(fake()->dateTimeBetween('2026-01-05', '2026-06-30')->format('Y-m-d'));

        return [
            'account_id' => Account::factory(),
            'ticker' => fake()->randomElement(['AAPL', 'MSFT', 'KO', 'XYZ']),
            'side' => TradeSide::Buy,
            'trade_date' => $tradeDate,
            'settlement_date' => MarketCalendar::stockSettlementDate($tradeDate),
            'shares' => Quantity::fromInteger(100),
            'price_per_share' => UnitPrice::fromInteger(fake()->numberBetween(10, 300)),
        ];
    }

    /**
     * Indicate that the trade is a sale.
     */
    public function sell(): static
    {
        return $this->state(fn (array $attributes) => [
            'side' => TradeSide::Sell,
        ]);
    }
}
