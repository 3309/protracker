<?php

namespace App\Livewire\Concerns;

use App\Support\TradeAssistant\TradeProposal;
use Closure;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Form;
use Throwable;

/**
 * Shared by the AI Trade Assistant's review panels. Each panel fills one
 * of the normal entry forms from the proposal, and saving goes through
 * that form's own checks and save.
 *
 * @phpstan-import-type ProposalArray from TradeProposal
 */
trait ReviewsTradeProposal
{
    /** @var ProposalArray */
    #[Locked]
    public array $proposal;

    /**
     * Identifies this proposal, so it can only be saved once.
     */
    #[Locked]
    public string $proposalKey;

    /**
     * The form fields that must be filled in before saving, as property => label.
     *
     * @return array<string, string>
     */
    abstract protected function requiredFields(): array;

    public function proposed(): TradeProposal
    {
        return TradeProposal::fromArray($this->proposal);
    }

    /**
     * The entry form the panel fills in. Panels whose form property is not
     * called "form" (to match the field partials) override this.
     */
    protected function reviewedForm(): Form
    {
        return $this->form;
    }

    /**
     * The labels of required fields that are still blank.
     *
     * @return list<string>
     */
    public function missingFields(): array
    {
        return array_values(array_filter(
            $this->requiredFields(),
            fn (string $label, string $property): bool => trim((string) $this->reviewedForm()->{$property}) === '',
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * Run the form's save at most once for this proposal, so a double click
     * or a repeated request cannot record the trade twice. A save that is
     * refused or fails can be tried again after correcting it.
     *
     * @template TRecord
     *
     * @param  Closure(): (TRecord|null)  $store
     * @return TRecord|null
     */
    protected function storeOnce(Closure $store): mixed
    {
        $claim = "trade-assistant:saved:{$this->proposalKey}";

        if (! Cache::add($claim, true, now()->addDay())) {
            $this->addError('proposal', 'This trade has already been saved. Start a new analysis to enter another one.');

            return null;
        }

        try {
            $record = $store();
        } catch (Throwable $exception) {
            Cache::forget($claim);

            throw $exception;
        }

        if ($record === null) {
            Cache::forget($claim);
        }

        return $record;
    }

    /**
     * Fill a form field from the proposal, leaving it blank when the AI did not find a value.
     */
    protected function fillFromProposal(string $property, string $field): void
    {
        $this->reviewedForm()->{$property} = $this->proposed()->value($field);
    }
}
