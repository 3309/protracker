<?php

namespace App\Livewire\Forms;

use App\Enums\OptionType;
use App\Enums\PriceInstrument;
use App\Livewire\Forms\Concerns\SharedFormInput;
use App\Models\Price;
use App\Models\Setting;
use App\Support\InputFormat;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * Enters a manual price for a stock or one option contract. A blank time
 * means the market close, 4:00 PM in the display time zone.
 */
class PriceForm extends Form
{
    use SharedFormInput;

    public const string MARKET_CLOSE = '16:00';

    public string $instrument = 'stock';

    public string $ticker = '';

    public string $option_type = '';

    public string $strike = '';

    public string $expires_on = '';

    public string $price = '';

    public string $as_of_date = '';

    public string $as_of_time = '';

    public string $reference_note = '';

    /**
     * Prepare the form for a given stock or option contract.
     */
    public function prepareFor(PriceInstrument $instrument, string $ticker, ?OptionType $optionType = null, ?UnitPrice $strike = null, ?string $expiresOn = null): void
    {
        $this->resetErrorBag();
        $this->reset('price', 'as_of_time', 'reference_note');
        $this->instrument = $instrument->value;
        $this->ticker = $ticker;
        $this->option_type = $optionType->value ?? '';
        $this->strike = $strike?->toDecimal() ?? '';
        $this->expires_on = $expiresOn ?? '';
    }

    public function isOption(): bool
    {
        return $this->instrument === PriceInstrument::Option->value;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $option = $this->isOption();

        return [
            'instrument' => ['required', Rule::enum(PriceInstrument::class)],
            'ticker' => ['required', 'regex:'.InputFormat::TICKER],
            'option_type' => $option ? ['required', Rule::enum(OptionType::class)] : [],
            'strike' => $option ? ['required', 'bail', 'regex:'.InputFormat::PRICE, $this->greaterThanZero(UnitPrice::class, 'The strike must be more than zero.')] : [],
            'expires_on' => $option ? ['required', 'date_format:Y-m-d'] : [],
            'price' => [
                'required',
                'bail',
                'regex:'.InputFormat::PRICE,
                function (string $attribute, mixed $value, Closure $fail) use ($option): void {
                    if (! $option && UnitPrice::fromDecimal((string) $value)->isZero()) {
                        $fail('A stock price must be more than zero.');
                    }
                },
            ],
            'as_of_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->todayInDisplayTimezone()],
            'as_of_time' => ['nullable', 'date_format:H:i'],
            'reference_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'ticker.regex' => 'Enter a ticker such as AAPL or BRK.B.',
            'strike.regex' => 'Enter a strike such as 50 or 52.5.',
            'price.regex' => 'Enter a price such as 52.10 or 0.35, with no more than four decimal places.',
            'as_of_date.before_or_equal' => 'A price cannot be dated in the future.',
            'as_of_time.date_format' => 'Enter a time such as 16:00, or leave it blank for the market close.',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'option_type' => 'put or call',
            'expires_on' => 'expiration date',
            'as_of_date' => 'date',
            'as_of_time' => 'time',
            'reference_note' => 'reference note',
        ];
    }

    public function store(): Price
    {
        $this->validate();

        $option = $this->isOption();
        $timezone = Setting::current()->display_timezone;
        $asOf = CarbonImmutable::createFromFormat('Y-m-d H:i', $this->as_of_date.' '.($this->nullIfBlank($this->as_of_time) ?? self::MARKET_CLOSE), $timezone);

        $price = Price::create([
            'instrument' => PriceInstrument::from($this->instrument),
            'ticker' => $this->normalizedTicker($this->ticker),
            'option_type' => $option ? OptionType::from($this->option_type) : null,
            'strike' => $option ? UnitPrice::fromDecimal($this->strike) : null,
            'expires_on' => $option ? $this->expires_on : null,
            'price' => UnitPrice::fromDecimal($this->price),
            'as_of' => $asOf ? $asOf->setTimezone(config('app.timezone')) : now(),
            'reference_note' => $this->nullIfBlank($this->reference_note),
        ]);

        $this->reset('price', 'as_of_time', 'reference_note');

        return $price;
    }

    private function todayInDisplayTimezone(): string
    {
        return CarbonImmutable::now(Setting::current()->display_timezone)->format('Y-m-d');
    }
}
