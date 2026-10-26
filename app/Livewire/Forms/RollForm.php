<?php

namespace App\Livewire\Forms;

use App\Actions\Options\EnsureCallsCovered;
use App\Actions\Options\SaveOptionTrade;
use App\Enums\OptionType;
use App\Exceptions\OptionPositionException;
use App\Exceptions\UncoveredCallException;
use App\Livewire\Forms\Concerns\SharedFormInput;
use App\Models\OptionPosition;
use App\Support\InputFormat;
use App\Support\MarketCalendar;
use App\Support\Money;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;
use Closure;
use Livewire\Form;

/**
 * Rolls an option: buys back some or all open contracts and sells a new
 * option of the same ticker and type in the same account, on the same date.
 *
 * Blank fees use the account's default option fee × contracts.
 */
class RollForm extends Form
{
    use SharedFormInput;

    public string $event_date = '';

    public string $contracts = '';

    public string $price_per_share = '';

    public string $closing_fees = '';

    public string $new_strike = '';

    public string $new_expires_on = '';

    public string $new_contracts = '';

    public string $new_premium_per_share = '';

    public string $new_opening_fees = '';

    public string $reference_note = '';

    public function prepare(OptionPosition $position): void
    {
        $this->resetErrorBag();
        $this->reset();
        $this->contracts = (string) $position->openContracts();
        $this->new_contracts = (string) $position->openContracts();
        $this->new_strike = $position->strike->toDecimal();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'event_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$this->reportingStartDate()],
            'contracts' => ['required', 'integer', 'between:1,100000'],
            'price_per_share' => ['required', 'bail', 'regex:'.InputFormat::PRICE, $this->greaterThanZero(UnitPrice::class, 'The buy-back price must be more than zero.')],
            'closing_fees' => ['nullable', 'regex:'.InputFormat::FEE],
            'new_strike' => ['required', 'bail', 'regex:'.InputFormat::PRICE, $this->greaterThanZero(UnitPrice::class, 'The strike must be more than zero.')],
            'new_expires_on' => [
                'required',
                'date_format:Y-m-d',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->event_date !== '' && (string) $value < $this->event_date) {
                        $fail('The new expiration date cannot be before the roll date.');
                    }
                },
            ],
            'new_contracts' => ['required', 'integer', 'between:1,100000'],
            'new_premium_per_share' => ['required', 'bail', 'regex:'.InputFormat::PRICE, $this->greaterThanZero(UnitPrice::class, 'The premium must be more than zero.')],
            'new_opening_fees' => ['nullable', 'regex:'.InputFormat::FEE],
            'reference_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'event_date.after_or_equal' => 'Rolls before the reporting start date are not entered.',
            '*.regex' => 'Enter an amount such as 1.50, with no more than four decimal places for prices and two for fees.',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'event_date' => 'roll date',
            'contracts' => 'contracts bought back',
            'price_per_share' => 'buy-back price',
            'closing_fees' => 'closing fee',
            'new_strike' => 'new strike',
            'new_expires_on' => 'new expiration date',
            'new_contracts' => 'new contracts',
            'new_premium_per_share' => 'new premium',
            'new_opening_fees' => 'new opening fee',
            'reference_note' => 'reference note',
        ];
    }

    /**
     * Record the roll. Returns the new leg, or null with an error when the
     * buy-back would conflict with the position.
     */
    public function store(OptionPosition $position): ?OptionPosition
    {
        $this->validate();

        $feePerContract = $position->account->option_fee_per_contract;
        $contracts = (int) $this->contracts;
        $newContracts = (int) $this->new_contracts;
        $settlementDate = MarketCalendar::optionSettlementDate(CarbonImmutable::parse($this->event_date))->format('Y-m-d');
        $note = $this->nullIfBlank($this->reference_note);

        $roll = fn () => app(SaveOptionTrade::class)->roll($position, [
            'event_date' => $this->event_date,
            'settlement_date' => $settlementDate,
            'contracts' => $contracts,
            'price_per_share' => UnitPrice::fromDecimal($this->price_per_share),
            'fees' => $this->nullIfBlank($this->closing_fees) !== null ? Money::fromDecimal($this->closing_fees) : $feePerContract->times($contracts),
            'reference_note' => $note,
        ], [
            'strike' => UnitPrice::fromDecimal($this->new_strike),
            'expires_on' => $this->new_expires_on,
            'opened_on' => $this->event_date,
            'opening_settlement_date' => $settlementDate,
            'contracts' => $newContracts,
            'multiplier' => $position->multiplier,
            'premium_per_share' => UnitPrice::fromDecimal($this->new_premium_per_share),
            'opening_fees' => $this->nullIfBlank($this->new_opening_fees) !== null ? Money::fromDecimal($this->new_opening_fees) : $feePerContract->times($newContracts),
            'reference_note' => $note,
        ]);

        try {
            $newLeg = $position->option_type === OptionType::Call
                ? app(EnsureCallsCovered::class)->afterChange(
                    $position->account_id,
                    $position->underlying,
                    CarbonImmutable::parse($this->event_date),
                    CarbonImmutable::parse($this->new_expires_on),
                    $roll,
                )
                : $roll();
        } catch (OptionPositionException $exception) {
            $this->addError($exception->field === 'event_date' ? 'event_date' : 'contracts', $exception->getMessage());

            return null;
        } catch (UncoveredCallException $exception) {
            $this->addError('new_contracts', $exception->getMessage());

            return null;
        }

        $this->reset();

        return $newLeg;
    }
}
