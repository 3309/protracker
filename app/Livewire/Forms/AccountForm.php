<?php

namespace App\Livewire\Forms;

use App\Enums\AccountType;
use App\Enums\Broker;
use App\Models\Account;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Livewire\Form;

class AccountForm extends Form
{
    private const string FEE_FORMAT = '/^\$?\d{1,6}(\.\d{1,2})?$/';

    public ?Account $account = null;

    public string $broker = '';

    public string $nickname = '';

    public string $account_type = '';

    public string $account_number_last4 = '';

    public string $option_fee_per_contract = '';

    public string $stock_fee_per_trade = '';

    public string $assignment_fee = '';

    public string $reason = '';

    /**
     * Fill the form from an existing account, or with the default fees for a new one.
     */
    public function setAccount(?Account $account = null): void
    {
        $this->account = $account;

        if ($account !== null) {
            $this->broker = $account->broker->value;
            $this->nickname = $account->nickname;
            $this->account_type = $account->account_type->value;
            $this->account_number_last4 = $account->account_number_last4 ?? '';
        }

        $source = $account ?? new Account;

        $this->option_fee_per_contract = $source->option_fee_per_contract->toDecimal();
        $this->stock_fee_per_trade = $source->stock_fee_per_trade->toDecimal();
        $this->assignment_fee = $source->assignment_fee->toDecimal();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'broker' => ['required', Rule::enum(Broker::class)],
            'nickname' => ['required', 'string', 'max:100', Rule::unique('accounts', 'nickname')->ignore($this->account)],
            'account_type' => ['required', Rule::enum(AccountType::class)],
            'account_number_last4' => ['nullable', 'digits:4'],
            'option_fee_per_contract' => ['required', 'regex:'.self::FEE_FORMAT],
            'stock_fee_per_trade' => ['required', 'regex:'.self::FEE_FORMAT],
            'assignment_fee' => ['required', 'regex:'.self::FEE_FORMAT],
            'reason' => [$this->account ? 'required' : 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            '*.regex' => 'Enter a dollar amount such as 0.67, with no more than two decimal places.',
            'reason.required' => 'Please explain why you are making this change. It is saved in the audit history.',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'account_type' => 'account type',
            'account_number_last4' => 'last 4 digits',
            'option_fee_per_contract' => 'option fee per contract',
            'stock_fee_per_trade' => 'stock fee per trade',
            'assignment_fee' => 'assignment/exercise fee',
        ];
    }

    public function store(): Account
    {
        $this->validate();

        return Account::create($this->accountAttributes());
    }

    public function update(): void
    {
        $this->validate();

        $this->account?->withAuditReason($this->reason)->update($this->accountAttributes());

        $this->reset('reason');
    }

    /**
     * @return array<string, mixed>
     */
    private function accountAttributes(): array
    {
        return [
            'broker' => Broker::from($this->broker),
            'nickname' => trim($this->nickname),
            'account_type' => AccountType::from($this->account_type),
            'account_number_last4' => $this->account_number_last4 !== '' ? $this->account_number_last4 : null,
            'option_fee_per_contract' => Money::fromDecimal($this->option_fee_per_contract),
            'stock_fee_per_trade' => Money::fromDecimal($this->stock_fee_per_trade),
            'assignment_fee' => Money::fromDecimal($this->assignment_fee),
        ];
    }
}
