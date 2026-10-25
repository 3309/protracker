<?php

namespace App\Livewire\Forms;

use App\Enums\OpeningBalanceSource;
use App\Enums\PositionOrigin;
use App\Livewire\Forms\Concerns\OpeningBalanceInput;
use App\Models\Account;
use App\Models\StockLot;
use App\Support\InputFormat;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\UnitPrice;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * Enters or corrects a stock lot held on the reporting start date.
 */
class StockLotForm extends Form
{
    use OpeningBalanceInput;

    public ?StockLot $stockLot = null;

    public string $ticker = '';

    public string $acquired_on = '';

    public string $shares = '';

    public string $cost_per_share = '';

    public string $fees = '0.00';

    public string $source = '';

    public string $reference_note = '';

    public string $reason = '';

    public function setStockLot(StockLot $stockLot): void
    {
        $this->stockLot = $stockLot;
        $this->ticker = $stockLot->ticker;
        $this->acquired_on = $stockLot->acquired_on->format('Y-m-d');
        $this->shares = $stockLot->shares->toDecimal();
        $this->cost_per_share = $stockLot->cost_per_share->toDecimal();
        $this->fees = $stockLot->fees->toDecimal();
        $this->source = $stockLot->source->value ?? '';
        $this->reference_note = $stockLot->reference_note ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'ticker' => ['required', 'regex:'.InputFormat::TICKER],
            'acquired_on' => ['required', 'date_format:Y-m-d', 'before:'.$this->reportingStartDate()],
            'shares' => ['required', 'bail', 'regex:'.InputFormat::SHARES, $this->greaterThanZero(Quantity::class, 'Enter more than zero shares.')],
            'cost_per_share' => ['required', 'regex:'.InputFormat::PRICE],
            'fees' => ['required', 'regex:'.InputFormat::FEE],
            'source' => ['required', Rule::enum(OpeningBalanceSource::class)],
            'reference_note' => ['nullable', 'string', 'max:1000'],
            'reason' => [$this->stockLot ? 'required' : 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'ticker.regex' => 'Enter a ticker such as AAPL or BRK.B.',
            'acquired_on.before' => 'An opening stock lot must have been bought before the reporting start date. Enter later purchases as trades.',
            'shares.regex' => 'Enter a number of shares such as 100 or 0.5, with no more than six decimal places.',
            'cost_per_share.regex' => 'Enter a price per share such as 50 or 49.1234, with no more than four decimal places.',
            'fees.regex' => 'Enter a dollar amount such as 0.67, with no more than two decimal places.',
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
            'acquired_on' => 'purchase date',
            'cost_per_share' => 'cost per share',
            'reference_note' => 'reference note',
        ];
    }

    public function store(Account $account): StockLot
    {
        $this->validate();

        return StockLot::create([
            'account_id' => $account->id,
            'origin' => PositionOrigin::OpeningBalance,
            ...$this->lotAttributes(),
        ]);
    }

    public function update(): void
    {
        $this->validate();

        $this->stockLot?->withAuditReason($this->reason)->update($this->lotAttributes());

        $this->reset('reason');
    }

    /**
     * @return array<string, mixed>
     */
    private function lotAttributes(): array
    {
        return [
            'ticker' => $this->normalizedTicker($this->ticker),
            'acquired_on' => $this->acquired_on,
            'shares' => Quantity::fromDecimal($this->shares),
            'cost_per_share' => UnitPrice::fromDecimal($this->cost_per_share),
            'fees' => Money::fromDecimal($this->fees),
            'source' => OpeningBalanceSource::from($this->source),
            'reference_note' => $this->nullIfBlank($this->reference_note),
        ];
    }
}
