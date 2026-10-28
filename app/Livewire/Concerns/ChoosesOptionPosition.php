<?php

namespace App\Livewire\Concerns;

use App\Models\OptionPosition;
use App\Support\TradeAssistant\PositionMatcher;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * For AI Trade Assistant panels that act on an option already recorded:
 * the owner chooses among the open positions that match the proposal,
 * and only those can be chosen. Use with ReviewsTradeProposal.
 */
trait ChoosesOptionPosition
{
    public string $position_id = '';

    /**
     * @return Collection<int, OptionPosition>
     */
    #[Computed]
    public function candidates(): Collection
    {
        return PositionMatcher::candidates($this->proposed());
    }

    /**
     * The chosen position, re-checked against the open matches every time.
     */
    public function chosenPosition(): ?OptionPosition
    {
        return $this->candidates->firstWhere('id', (int) $this->position_id);
    }

    /**
     * Choose the position when exactly one matches; with several, the owner chooses.
     */
    protected function chooseOnlyCandidate(): void
    {
        if ($this->candidates->count() === 1) {
            $this->position_id = (string) $this->candidates->first()?->id;
        }
    }

    /**
     * Required fields still blank, with the position first when none is chosen.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        return [...($this->chosenPosition() === null ? ['Option position'] : []), ...$this->missingFields()];
    }
}
