<?php

namespace App\Livewire\Forms;

use App\Livewire\Forms\Concerns\SharedFormInput;
use App\Models\Account;
use App\Models\Dividend;
use App\Support\DividendRate;
use App\Support\InputFormat;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * Enters or corrects a dividend, and confirms its actual amount.
 *
 * The same account, ticker and ex-date can only be entered once (voided
 * entries do not count), so a dividend is never recorded twice.
 */
class DividendForm extends Form
{
    use SharedFormInput;

    public ?Dividend $dividend = null;

    public string $account_id = '';

    public string $ticker = '';

    public string $ex_date = '';

    public string $pay_date = '';

    public string $amount_per_share = '';

    public string $actual_amount = '';

    public string $reference_note = '';

    public string $reason = '';

    public function setDividend(Dividend $dividend): void
    {
        $this->dividend = $dividend;
        $this->account_id = (string) $dividend->account_id;
        $this->ticker = $dividend->ticker;
        $this->ex_date = $dividend->ex_date->format('Y-m-d');
        $this->pay_date = $dividend->pay_date->format('Y-m-d');
        $this->amount_per_share = $dividend->amount_per_share->toDecimal();
        $this->actual_amount = $dividend->actual_amount?->toDecimal() ?? '';
        $this->reference_note = $dividend->reference_note ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $creating = $this->dividend === null;
        $accountId = $this->dividend === null ? (int) $this->account_id : $this->dividend->account_id;

        return [
            'account_id' => $creating ? ['required', Rule::exists('accounts', 'id')->whereNull('archived_at')] : [],
            'ticker' => ['required', 'regex:'.InputFormat::TICKER],
            'ex_date' => [
                'required',
                'date_format:Y-m-d',
                Rule::unique('dividends', 'ex_date')
                    ->where('account_id', $accountId)
                    ->where('ticker', $this->normalizedTicker($this->ticker))
                    ->whereNull('voided_at')
                    ->ignore($this->dividend),
            ],
            'pay_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$this->reportingStartDate()],
            'amount_per_share' => ['required', 'bail', 'regex:'.InputFormat::DIVIDEND_RATE, $this->greaterThanZero(DividendRate::class, 'The amount per share must be more than zero.')],
            'reference_note' => ['nullable', 'string', 'max:1000'],
            'reason' => [$creating ? 'nullable' : 'required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'account_id.required' => 'Choose the account.',
            'account_id.exists' => 'Choose an active account.',
            'ticker.regex' => 'Enter a ticker such as AAPL or BRK.B.',
            'ex_date.unique' => 'This dividend is already recorded for this account, ticker and ex-date.',
            'pay_date.after_or_equal' => 'Dividends paid before the reporting start date are already inside the opening cash.',
            'amount_per_share.regex' => 'Enter the amount per share such as 0.50 or 0.123456, with no more than six decimal places.',
            'reason.required' => 'Please explain why you are making this change. It is saved in the audit history.',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'account_id' => 'account',
            'ex_date' => 'ex-date',
            'pay_date' => 'pay date',
            'amount_per_share' => 'amount per share',
            'reference_note' => 'reference note',
        ];
    }

    public function store(): Dividend
    {
        $this->validate();

        return Dividend::create([
            'account_id' => (int) $this->account_id,
            ...$this->dividendAttributes(),
        ]);
    }

    /**
     * Save corrected details. A confirmed dividend keeps its actual amount,
     * and its expected amount is worked out again from the corrected details.
     */
    public function update(): void
    {
        $this->validate();

        $dividend = $this->dividend;

        if ($dividend === null) {
            return;
        }

        $dividend->withAuditReason($this->reason)->fill($this->dividendAttributes())->save();

        if ($dividend->actual_amount !== null) {
            $dividend->confirm($dividend->actual_amount, $this->reason);
        }

        $this->reset('reason');
    }

    /**
     * Confirm the actual amount received. Changing an amount already
     * confirmed needs a reason.
     */
    public function confirm(?Money $actualAmount = null): void
    {
        $dividend = $this->dividend;

        if ($dividend === null) {
            return;
        }

        if ($actualAmount === null) {
            $this->validate(
                ['actual_amount' => ['required', 'regex:'.InputFormat::MONEY], 'reason' => [$dividend->isConfirmed() ? 'required' : 'nullable', 'string', 'max:1000']],
                ['actual_amount.regex' => 'Enter the amount received such as 42.50, with no more than two decimal places.', 'reason.required' => 'Please explain why you are changing the confirmed amount.'],
                ['actual_amount' => 'actual amount'],
            );

            $actualAmount = Money::fromDecimal($this->actual_amount);
        }

        $dividend->confirm($actualAmount, $this->nullIfBlank($this->reason));

        $this->actual_amount = $actualAmount->toDecimal();
        $this->reset('reason');
    }

    /**
     * @return array<string, mixed>
     */
    private function dividendAttributes(): array
    {
        return [
            'ticker' => $this->normalizedTicker($this->ticker),
            'ex_date' => $this->ex_date,
            'pay_date' => $this->pay_date,
            'amount_per_share' => DividendRate::fromDecimal($this->amount_per_share),
            'reference_note' => $this->nullIfBlank($this->reference_note),
        ];
    }
}
