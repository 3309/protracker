<?php

namespace App\Livewire\Forms;

use App\Livewire\Forms\Concerns\SharedFormInput;
use App\Models\BrokerBalance;
use App\Support\InputFormat;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * Enters or corrects a cash balance reported by the broker, for
 * reconciliation. Each account can have one balance per date (voided
 * entries do not count).
 */
class BrokerBalanceForm extends Form
{
    use SharedFormInput;

    public ?BrokerBalance $brokerBalance = null;

    public string $account_id = '';

    public string $as_of_date = '';

    public string $total_cash = '';

    public string $settled_cash = '';

    public string $reference_note = '';

    public string $reason = '';

    public function setBrokerBalance(BrokerBalance $brokerBalance): void
    {
        $this->brokerBalance = $brokerBalance;
        $this->account_id = (string) $brokerBalance->account_id;
        $this->as_of_date = $brokerBalance->as_of_date->format('Y-m-d');
        $this->total_cash = $brokerBalance->total_cash->toDecimal();
        $this->settled_cash = $brokerBalance->settled_cash?->toDecimal() ?? '';
        $this->reference_note = $brokerBalance->reference_note ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $creating = $this->brokerBalance === null;
        $accountId = $this->brokerBalance === null ? (int) $this->account_id : $this->brokerBalance->account_id;

        return [
            'account_id' => $creating ? ['required', Rule::exists('accounts', 'id')] : [],
            'as_of_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.$this->reportingStartDate(),
                Rule::unique('broker_balances', 'as_of_date')->where('account_id', $accountId)->whereNull('voided_at')->ignore($this->brokerBalance),
            ],
            'total_cash' => ['required', 'regex:'.InputFormat::SIGNED_MONEY],
            'settled_cash' => ['nullable', 'regex:'.InputFormat::SIGNED_MONEY],
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
            'as_of_date.unique' => 'A broker balance is already entered for this account and date. Correct that one instead.',
            '*.regex' => 'Enter a dollar amount such as 2000 or -150.25, with no more than two decimal places.',
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
            'as_of_date' => 'date',
            'total_cash' => 'cash balance',
            'settled_cash' => 'settled cash',
            'reference_note' => 'reference note',
        ];
    }

    public function store(): BrokerBalance
    {
        $this->validate();

        $balance = BrokerBalance::create([
            'account_id' => (int) $this->account_id,
            ...$this->balanceAttributes(),
        ]);

        $this->reset('as_of_date', 'total_cash', 'settled_cash', 'reference_note');

        return $balance;
    }

    public function update(): void
    {
        $this->validate();

        $this->brokerBalance?->withAuditReason($this->reason)->update($this->balanceAttributes());

        $this->reset('reason');
    }

    /**
     * @return array<string, mixed>
     */
    private function balanceAttributes(): array
    {
        return [
            'as_of_date' => $this->as_of_date,
            'total_cash' => Money::fromDecimal($this->total_cash),
            'settled_cash' => $this->nullIfBlank($this->settled_cash) !== null ? Money::fromDecimal($this->settled_cash) : null,
            'reference_note' => $this->nullIfBlank($this->reference_note),
        ];
    }
}
