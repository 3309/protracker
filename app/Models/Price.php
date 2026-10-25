<?php

namespace App\Models;

use App\Casts\CalendarDate;
use App\Enums\OptionType;
use App\Enums\PriceInstrument;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\Voidable;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;
use Database\Factories\PriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A manually entered price for a stock or one option contract, as of a
 * moment in time. Prices are kept as a history; the latest one is used.
 * They are not live quotes and never affect realized profit.
 *
 * @property int $id
 * @property PriceInstrument $instrument
 * @property string $ticker
 * @property OptionType|null $option_type
 * @property UnitPrice|null $strike
 * @property CarbonImmutable|null $expires_on
 * @property UnitPrice $price
 * @property Carbon $as_of
 * @property string|null $reference_note
 * @property Carbon|null $voided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['instrument', 'ticker', 'option_type', 'strike', 'expires_on', 'price', 'as_of', 'reference_note'])]
class Price extends Model
{
    /** @use HasFactory<PriceFactory> */
    use Auditable, HasFactory, Voidable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'instrument' => PriceInstrument::class,
            'option_type' => OptionType::class,
            'strike' => UnitPrice::class,
            'expires_on' => CalendarDate::class,
            'price' => UnitPrice::class,
            'as_of' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * A key naming what the price is for, e.g. "stock|XYZ" or
     * "option|XYZ|put|500000|2026-03-20".
     */
    public static function identityKey(PriceInstrument $instrument, string $ticker, ?OptionType $optionType = null, ?UnitPrice $strike = null, ?string $expiresOn = null): string
    {
        return $instrument === PriceInstrument::Stock
            ? "stock|{$ticker}"
            : "option|{$ticker}|{$optionType?->value}|{$strike?->units}|{$expiresOn}";
    }

    public function identity(): string
    {
        return self::identityKey($this->instrument, $this->ticker, $this->option_type, $this->strike, $this->expires_on?->format('Y-m-d'));
    }

    /**
     * What the price is for, e.g. "XYZ" or "XYZ $50.00 Put, expires Mar 20, 2026".
     */
    public function description(): string
    {
        if ($this->instrument === PriceInstrument::Stock) {
            return $this->ticker;
        }

        return "{$this->ticker} {$this->strike?->format()} {$this->option_type?->label()}, expires {$this->expires_on?->format('M j, Y')}";
    }
}
