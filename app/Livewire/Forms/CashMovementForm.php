<?php

namespace App\Livewire\Forms;

use App\Enums\CashMovementType;
use App\Livewire\Forms\Concerns\SharedFormInput;
use App\Models\CashMovement;
use App\Support\InputFormat;
use App\Support\Money;
use Closure;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * Enters or corrects a deposit, withdrawal, interest payment or other
 * broker credit or charge. A blank settlement date means the same day.
 */
class CashMovementForm extends Form
{
    use SharedFormInput;

    public ?CashMovement $cashMovement = null;

    public string $account_id = '';

    public string $movement_type = '';

    public string $movement_date = '';

    public string $settlement_date = '';

    public string $amount = '';

    public string $reference_note = '';

    public string $reason = '';

    public function setCashMovement(CashMovement $cashMovement): void
    {
        $this->cashMovement = $cashMovement;
        $this->account_id = (string) $cashMovement->account_id;
        $this->movement_type = $cashMovement->movement_type->value;
        $this->movement_date = $cashMovement->movement_date->format('Y-m-d');
        $this->settlement_date = $cashMovement->settlement_date->format('Y-m-d');
        $this->amount = $cashMovement->amount->toDecimal();
        $this->reference_note = $cashMovement->reference_note ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $creating = $this->cashMovement === null;

        return [
            'account_id' => $creating ? ['required', Rule::exists('accounts', 'id')->whereNull('archived_at')] : [],
            'movement_type' => ['required', Rule::enum(CashMovementType::class)],
            'movement_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$this->reportingStartDate()],
            'settlement_date' => [
                'nullable',
                'date_format:Y-m-d',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->movement_date !== '' && (string) $value < $this->movement_date) {
                        $fail('The settlement date cannot be before the date.');
                    }
                },
            ],
            'amount' => ['required', 'bail', 'regex:'.InputFormat::MONEY, function (string $attribute, mixed $value, Closure $fail): void {
                if (Money::fromDecimal((string) $value)->isZero()) {
                    $fail('The amount must be more than zero.');
                }
            }],
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
            'movement_type.required' => 'Choose what kind of movement this is.',
            'movement_date.after_or_equal' => 'Movements before the reporting start date are already inside the opening cash.',
            'amount.regex' => 'Enter an amount such as 1000 or 12.34, with no more than two decimal places. The type decides whether it adds or removes cash.',
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
            'movement_type' => 'type',
            'movement_date' => 'date',
            'settlement_date' => 'settlement date',
            'reference_note' => 'reference note',
        ];
    }

    public function store(): CashMovement
    {
        $this->validate();

        return CashMovement::create([
            'account_id' => (int) $this->account_id,
            ...$this->movementAttributes(),
        ]);
    }

    public function update(): void
    {
        $this->validate();

        $this->cashMovement?->withAuditReason($this->reason)->update($this->movementAttributes());

        $this->reset('reason');
    }

    /**
     * @return array<string, mixed>
     */
    private function movementAttributes(): array
    {
        return [
            'movement_type' => CashMovementType::from($this->movement_type),
            'movement_date' => $this->movement_date,
            'settlement_date' => $this->nullIfBlank($this->settlement_date) ?? $this->movement_date,
            'amount' => Money::fromDecimal($this->amount),
            'reference_note' => $this->nullIfBlank($this->reference_note),
        ];
    }
}
