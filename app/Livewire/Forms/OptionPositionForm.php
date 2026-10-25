<?php

namespace App\Livewire\Forms;

use App\Enums\OpeningBalanceSource;
use App\Enums\OptionSide;
use App\Enums\OptionType;
use App\Enums\PositionOrigin;
use App\Livewire\Forms\Concerns\OpeningBalanceInput;
use App\Models\Account;
use App\Models\OptionPosition;
use App\Support\InputFormat;
use App\Support\Money;
use App\Support\UnitPrice;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * Enters or corrects a short option that was still open on the reporting
 * start date. Only the contracts still open on that date are entered.
 */
class OptionPositionForm extends Form
{
    use OpeningBalanceInput;

    public ?OptionPosition $optionPosition = null;

    public string $underlying = '';

    public string $option_type = '';

    public string $strike = '';

    public string $expires_on = '';

    public string $opened_on = '';

    public string $contracts = '';

    public string $multiplier = '100';

    public string $premium_per_share = '';

    public string $opening_fees = '';

    public string $source = '';

    public string $reference_note = '';

    public string $reason = '';

    public function setOptionPosition(OptionPosition $optionPosition): void
    {
        $this->optionPosition = $optionPosition;
        $this->underlying = $optionPosition->underlying;
        $this->option_type = $optionPosition->option_type->value;
        $this->strike = $optionPosition->strike->toDecimal();
        $this->expires_on = $optionPosition->expires_on->format('Y-m-d');
        $this->opened_on = $optionPosition->opened_on->format('Y-m-d');
        $this->contracts = (string) $optionPosition->contracts;
        $this->multiplier = (string) $optionPosition->multiplier;
        $this->premium_per_share = $optionPosition->premium_per_share->toDecimal();
        $this->opening_fees = $optionPosition->opening_fees->toDecimal();
        $this->source = $optionPosition->source->value ?? '';
        $this->reference_note = $optionPosition->reference_note ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'underlying' => ['required', 'regex:'.InputFormat::TICKER],
            'option_type' => ['required', Rule::enum(OptionType::class)],
            'strike' => ['required', 'bail', 'regex:'.InputFormat::PRICE, $this->greaterThanZero(UnitPrice::class, 'The strike must be more than zero.')],
            'opened_on' => ['required', 'date_format:Y-m-d', 'before:'.$this->reportingStartDate()],
            'expires_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$this->reportingStartDate()],
            'contracts' => ['required', 'integer', 'between:1,100000'],
            'multiplier' => ['required', 'integer', 'between:1,100000'],
            'premium_per_share' => ['required', 'bail', 'regex:'.InputFormat::PRICE, $this->greaterThanZero(UnitPrice::class, 'The premium must be more than zero.')],
            'opening_fees' => ['nullable', 'regex:'.InputFormat::FEE],
            'source' => ['required', Rule::enum(OpeningBalanceSource::class)],
            'reference_note' => ['nullable', 'string', 'max:1000'],
            'reason' => [$this->optionPosition ? 'required' : 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'underlying.regex' => 'Enter a ticker such as AAPL or BRK.B.',
            'strike.regex' => 'Enter a strike price such as 50 or 52.5, with no more than four decimal places.',
            'premium_per_share.regex' => 'Enter the premium per share such as 1.20, with no more than four decimal places.',
            'opening_fees.regex' => 'Enter a dollar amount such as 2.01, with no more than two decimal places.',
            'opened_on.before' => 'An opening option must have been opened before the reporting start date. Enter later options as trades.',
            'expires_on.after_or_equal' => 'This option expired before the reporting start date, so it was not open on that date.',
            'source.required' => 'Choose where this figure came from.',
            'reason.required' => 'Please explain why you are making this change. It is saved in the audit history.',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'underlying' => 'ticker',
            'option_type' => 'put or call',
            'expires_on' => 'expiration date',
            'opened_on' => 'date opened',
            'premium_per_share' => 'premium per share',
            'opening_fees' => 'opening fees',
            'reference_note' => 'reference note',
        ];
    }

    public function store(Account $account): OptionPosition
    {
        $this->validate();

        return OptionPosition::create([
            'account_id' => $account->id,
            'side' => OptionSide::Short,
            'origin' => PositionOrigin::OpeningBalance,
            ...$this->positionAttributes($account),
        ]);
    }

    public function update(): void
    {
        $this->validate();

        if ($this->optionPosition === null) {
            return;
        }

        $this->optionPosition
            ->withAuditReason($this->reason)
            ->update($this->positionAttributes($this->optionPosition->account));

        $this->opening_fees = $this->optionPosition->opening_fees->toDecimal();
        $this->reset('reason');
    }

    /**
     * @return array<string, mixed>
     */
    private function positionAttributes(Account $account): array
    {
        $contracts = (int) $this->contracts;

        return [
            'underlying' => $this->normalizedTicker($this->underlying),
            'option_type' => OptionType::from($this->option_type),
            'strike' => UnitPrice::fromDecimal($this->strike),
            'expires_on' => $this->expires_on,
            'opened_on' => $this->opened_on,
            'contracts' => $contracts,
            'multiplier' => (int) $this->multiplier,
            'premium_per_share' => UnitPrice::fromDecimal($this->premium_per_share),
            'opening_fees' => $this->nullIfBlank($this->opening_fees) !== null
                ? Money::fromDecimal($this->opening_fees)
                : $account->option_fee_per_contract->times($contracts),
            'source' => OpeningBalanceSource::from($this->source),
            'reference_note' => $this->nullIfBlank($this->reference_note),
        ];
    }
}
