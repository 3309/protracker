<?php

namespace App\Livewire\Forms;

use App\Actions\Options\SaveOptionTrade;
use App\Exceptions\OptionPositionException;
use App\Livewire\Forms\Concerns\SharedFormInput;
use App\Models\OptionEvent;
use App\Models\OptionPosition;
use App\Support\InputFormat;
use App\Support\MarketCalendar;
use App\Support\Money;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;
use Closure;
use Livewire\Form;

/**
 * Closes some or all open contracts at a price, or corrects such a close:
 * buys back a sold option, or sells a bought option to close.
 *
 * A blank fee uses the account's default option fee × contracts. A blank
 * settlement date is calculated as the next trading day.
 */
class BuyToCloseForm extends Form
{
    use SharedFormInput;

    public ?OptionEvent $optionEvent = null;

    public string $event_date = '';

    public string $settlement_date = '';

    public string $contracts = '';

    public string $price_per_share = '';

    public string $fees = '';

    public string $reference_note = '';

    public string $reason = '';

    public function setOptionEvent(OptionEvent $optionEvent): void
    {
        $this->optionEvent = $optionEvent;
        $this->event_date = $optionEvent->event_date->format('Y-m-d');
        $this->settlement_date = $optionEvent->settlement_date_overridden ? ($optionEvent->settlement_date?->format('Y-m-d') ?? '') : '';
        $this->contracts = (string) $optionEvent->contracts;
        $this->price_per_share = $optionEvent->price_per_share?->toDecimal() ?? '';
        $this->fees = $optionEvent->fees->toDecimal();
        $this->reference_note = $optionEvent->reference_note ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'event_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$this->reportingStartDate()],
            'settlement_date' => [
                'nullable',
                'date_format:Y-m-d',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->event_date !== '' && (string) $value < $this->event_date) {
                        $fail('The settlement date cannot be before the trade date.');
                    }
                },
            ],
            'contracts' => ['required', 'integer', 'between:1,100000'],
            'price_per_share' => ['required', 'bail', 'regex:'.InputFormat::PRICE, $this->greaterThanZero(UnitPrice::class, 'The price must be more than zero.')],
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
            'event_date.after_or_equal' => 'Closes before the reporting start date are not entered; enter only the contracts still open on that date.',
            'price_per_share.regex' => 'Enter the price per share such as 0.40, with no more than four decimal places.',
            'fees.regex' => 'Enter a dollar amount such as 0.67, with no more than two decimal places.',
            'reason.required' => 'Please explain why you are making this change. It is saved in the audit history.',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'event_date' => 'trade date',
            'settlement_date' => 'settlement date',
            'price_per_share' => 'price per share',
            'reference_note' => 'reference note',
        ];
    }

    /**
     * Record the close. Returns null, with an error on the matching field,
     * when it would close more contracts than are open or fall outside the
     * option's life.
     */
    public function store(OptionPosition $position): ?OptionEvent
    {
        $this->validate();

        try {
            $event = $position->isLong()
                ? app(SaveOptionTrade::class)->sellToClose($position, $this->eventAttributes($position))
                : app(SaveOptionTrade::class)->buyToClose($position, $this->eventAttributes($position));
        } catch (OptionPositionException $exception) {
            $this->addError($exception->field, $exception->getMessage());

            return null;
        }

        $this->reset();

        return $event;
    }

    /**
     * Save a correction. Returns false, with an error, when it would leave
     * the position inconsistent.
     */
    public function update(): bool
    {
        $this->validate();

        $event = $this->optionEvent;

        if ($event === null) {
            return false;
        }

        try {
            app(SaveOptionTrade::class)->updateEvent($event, $this->eventAttributes($event->position), $this->reason);
        } catch (OptionPositionException $exception) {
            $event->refresh();
            $this->addError($exception->field, $exception->getMessage());

            return false;
        }

        $this->setOptionEvent($event);
        $this->reset('reason');

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function eventAttributes(OptionPosition $position): array
    {
        $contracts = (int) $this->contracts;
        $settlementDate = $this->nullIfBlank($this->settlement_date);

        return [
            'event_date' => $this->event_date,
            'settlement_date' => $settlementDate ?? MarketCalendar::optionSettlementDate(CarbonImmutable::parse($this->event_date))->format('Y-m-d'),
            'settlement_date_overridden' => $settlementDate !== null,
            'contracts' => $contracts,
            'price_per_share' => UnitPrice::fromDecimal($this->price_per_share),
            'fees' => $this->nullIfBlank($this->fees) !== null
                ? Money::fromDecimal($this->fees)
                : $position->account->option_fee_per_contract->times($contracts),
            'reference_note' => $this->nullIfBlank($this->reference_note),
        ];
    }
}
