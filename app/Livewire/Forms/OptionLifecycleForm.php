<?php

namespace App\Livewire\Forms;

use App\Actions\Options\SaveOptionTrade;
use App\Enums\OptionEventType;
use App\Exceptions\InsufficientSharesException;
use App\Exceptions\OptionPositionException;
use App\Livewire\Forms\Concerns\SharedFormInput;
use App\Models\OptionEvent;
use App\Models\OptionPosition;
use App\Support\InputFormat;
use App\Support\Money;
use Livewire\Form;

/**
 * Records, or corrects, an expiry or an assignment of some or all open
 * contracts.
 *
 * For an assignment, the fee is charged on the stock trade it creates; a
 * blank fee uses the account's default assignment fee.
 */
class OptionLifecycleForm extends Form
{
    use SharedFormInput;

    public ?OptionEvent $optionEvent = null;

    public string $event_type = '';

    public string $event_date = '';

    public string $contracts = '';

    public string $fees = '';

    public string $reference_note = '';

    public string $reason = '';

    /**
     * Prepare a new expiry or assignment for all open contracts on the
     * expiration date.
     */
    public function prepare(OptionPosition $position, OptionEventType $type): void
    {
        $this->resetErrorBag();
        $this->reset();
        $this->event_type = $type->value;
        $this->event_date = $position->expires_on->format('Y-m-d');
        $this->contracts = (string) $position->openContracts();
    }

    public function setOptionEvent(OptionEvent $optionEvent): void
    {
        $this->optionEvent = $optionEvent;
        $this->event_type = $optionEvent->event_type->value;
        $this->event_date = $optionEvent->event_date->format('Y-m-d');
        $this->contracts = (string) $optionEvent->contracts;
        $this->fees = $optionEvent->stockTrade?->fees->toDecimal() ?? '';
        $this->reference_note = $optionEvent->reference_note ?? '';
    }

    public function isAssignment(): bool
    {
        return $this->event_type === OptionEventType::Assigned->value;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'event_type' => ['required', 'in:'.OptionEventType::Expired->value.','.OptionEventType::Assigned->value],
            'event_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$this->reportingStartDate()],
            'contracts' => ['required', 'integer', 'between:1,100000'],
            'fees' => ['nullable', 'regex:'.InputFormat::FEE],
            'reference_note' => ['nullable', 'string', 'max:1000'],
            'reason' => [$this->optionEvent ? 'required' : 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'event_date.after_or_equal' => 'Events before the reporting start date are not entered; enter only the contracts still open on that date.',
            'fees.regex' => 'Enter a dollar amount such as 0.00, with no more than two decimal places.',
            'reason.required' => 'Please explain why you are making this change. It is saved in the audit history.',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'event_date' => 'date',
            'fees' => 'assignment fee',
            'reference_note' => 'reference note',
        ];
    }

    /**
     * Record the expiry or assignment. Returns null, with an error, when it
     * would conflict with the position or (for a call) the shares held.
     */
    public function store(OptionPosition $position): ?OptionEvent
    {
        $this->validate();

        $attributes = $this->eventAttributes();

        try {
            $event = $this->isAssignment()
                ? app(SaveOptionTrade::class)->assign($position, $attributes, $this->stockFee($position))
                : app(SaveOptionTrade::class)->expire($position, $attributes);
        } catch (OptionPositionException|InsufficientSharesException $exception) {
            $this->addError($this->errorField($exception), $exception->getMessage());

            return null;
        }

        $this->reset();

        return $event;
    }

    /**
     * Save a correction. Returns false, with an error, when it would leave the
     * position or the stock lots inconsistent.
     */
    public function update(): bool
    {
        $this->validate();

        $event = $this->optionEvent;

        if ($event === null) {
            return false;
        }

        try {
            app(SaveOptionTrade::class)->updateEvent(
                $event,
                $this->eventAttributes(),
                $this->reason,
                $this->isAssignment() ? $this->stockFee($event->position) : null,
            );
        } catch (OptionPositionException|InsufficientSharesException $exception) {
            $event->refresh();
            $this->addError($this->errorField($exception), $exception->getMessage());

            return false;
        }

        $this->setOptionEvent($event->refresh());
        $this->reset('reason');

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function eventAttributes(): array
    {
        return [
            'event_date' => $this->event_date,
            'contracts' => (int) $this->contracts,
            'reference_note' => $this->nullIfBlank($this->reference_note),
        ];
    }

    private function stockFee(OptionPosition $position): Money
    {
        return $this->nullIfBlank($this->fees) !== null ? Money::fromDecimal($this->fees) : $position->account->assignment_fee;
    }

    private function errorField(OptionPositionException|InsufficientSharesException $exception): string
    {
        if ($exception instanceof OptionPositionException && in_array($exception->field, ['event_date', 'reason'], true)) {
            return $exception->field;
        }

        return 'contracts';
    }
}
