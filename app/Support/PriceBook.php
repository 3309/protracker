<?php

namespace App\Support;

use App\Enums\PriceInstrument;
use App\Models\OptionPosition;
use App\Models\Price;
use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * The latest manually entered price of every stock and option contract, and
 * whether each is stale (older than the stale-price setting).
 */
final readonly class PriceBook
{
    /**
     * @param  array<string, Price>  $latest  latest price by identity key
     */
    private function __construct(
        private array $latest,
        private int $staleAfterDays,
        private string $displayTimezone,
        private CarbonImmutable $today,
    ) {}

    /**
     * @param  CarbonImmutable  $today  today's calendar date
     */
    public static function load(CarbonImmutable $today): self
    {
        $settings = Setting::current();

        $latest = Price::query()->notVoided()->orderBy('as_of')->orderBy('id')->get()
            ->keyBy(fn (Price $price) => $price->identity())
            ->all();

        return new self($latest, $settings->stale_price_threshold_days, $settings->display_timezone, $today);
    }

    public function forStock(string $ticker): ?Price
    {
        return $this->latest[Price::identityKey(PriceInstrument::Stock, $ticker)] ?? null;
    }

    public function forOption(OptionPosition $position): ?Price
    {
        $key = Price::identityKey(PriceInstrument::Option, $position->underlying, $position->option_type, $position->strike, $position->expires_on->format('Y-m-d'));

        return $this->latest[$key] ?? null;
    }

    /**
     * Whether the price is older than the stale-price threshold, counted in
     * calendar days in the display time zone.
     */
    public function isStale(Price $price): bool
    {
        return $this->ageInDays($price) > $this->staleAfterDays;
    }

    public function ageInDays(Price $price): int
    {
        $priceDate = CarbonImmutable::parse($price->as_of->setTimezone($this->displayTimezone)->format('Y-m-d'));

        return (int) $priceDate->diffInDays($this->today, false);
    }

    public function staleAfterDays(): int
    {
        return $this->staleAfterDays;
    }

    public function displayTimezone(): string
    {
        return $this->displayTimezone;
    }
}
