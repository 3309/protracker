<?php

namespace App\Livewire\Forms;

use App\Enums\OpeningBalanceSource;
use App\Livewire\Forms\Concerns\SharedFormInput;
use App\Models\Account;
use App\Models\AccountOpening;
use App\Support\InputFormat;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Livewire\Form;

class OpeningCashForm extends Form
{
    use SharedFormInput;

    public ?AccountOpening $opening = null;

    public string $cash_balance = '';

    public string $unsettled_cash = '';

    public string $cash_source = '';

    public string $cash_note = '';

    public string $reason = '';

    public function setOpening(?AccountOpening $opening): void
    {
        $this->opening = $opening;

        if ($opening?->hasCash()) {
            $this->cash_balance = $opening->cash_balance?->toDecimal() ?? '';
            $this->unsettled_cash = $opening->unsettled_cash?->toDecimal() ?? '';
            $this->cash_source = $opening->cash_source->value ?? '';
            $this->cash_note = $opening->cash_note ?? '';
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'cash_balance' => ['required', 'regex:'.InputFormat::SIGNED_MONEY],
            'unsettled_cash' => ['nullable', 'regex:'.InputFormat::SIGNED_MONEY],
            'cash_source' => ['required', Rule::enum(OpeningBalanceSource::class)],
            'cash_note' => ['nullable', 'string', 'max:1000'],
            'reason' => [$this->opening?->hasCash() ? 'required' : 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            '*.regex' => 'Enter a dollar amount such as 2000 or -150.25, with no more than two decimal places.',
            'cash_source.required' => 'Choose where this figure came from.',
            'reason.required' => 'Please explain why you are making this change. It is saved in the audit history.',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'cash_balance' => 'starting cash',
            'unsettled_cash' => 'unsettled cash',
            'cash_source' => 'source',
            'cash_note' => 'reference note',
        ];
    }

    /**
     * Save the starting cash, creating the account's opening record the first time.
     */
    public function save(Account $account): AccountOpening
    {
        $this->validate();

        $opening = $this->opening ?? new AccountOpening([
            'account_id' => $account->id,
            'as_of_date' => $this->reportingStartDate(),
        ]);

        $opening->withAuditReason($this->nullIfBlank($this->reason))->fill([
            'cash_balance' => Money::fromDecimal($this->cash_balance),
            'unsettled_cash' => $this->nullIfBlank($this->unsettled_cash) !== null ? Money::fromDecimal($this->unsettled_cash) : null,
            'cash_source' => OpeningBalanceSource::from($this->cash_source),
            'cash_note' => $this->nullIfBlank($this->cash_note),
        ])->save();

        $this->opening = $opening;
        $this->reset('reason');

        return $opening;
    }
}
