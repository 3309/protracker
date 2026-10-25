<?php

use App\Livewire\ReportPage;
use App\Models\OptionPosition;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

new #[Title('Options')] class extends ReportPage
{
    /** "open", "closed" or "all" (which also shows voided positions). */
    #[Url]
    public string $status = 'open';

    /**
     * @return Collection<int, OptionPosition>
     */
    #[Computed]
    public function positions(): Collection
    {
        $query = OptionPosition::query()->with('account')->withClosedContracts();

        $this->applyAccountFilter($query);

        if ($this->status === 'closed') {
            $query->notVoided()->closed();
        } elseif ($this->status !== 'all') {
            $query->notVoided()->open();
        }

        return $query->orderBy('expires_on')->orderBy('underlying')->orderBy('id')->get();
    }

    /**
     * Calendar days from today until expiration; negative once expired.
     */
    public function daysToExpiry(OptionPosition $position): int
    {
        return (int) $this->today()->diffInDays($position->expires_on, false);
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold">Options</h1>
        <a href="{{ route('options.create') }}" class="btn-primary" wire:navigate>Sell to open</a>
    </div>

    <div class="flex flex-wrap items-end gap-4">
        <div class="grow">
            @include('partials.report-filters', ['showPeriod' => false])
        </div>
        <div class="card">
            <label for="status" class="form-label">Show</label>
            <select id="status" wire:model.live="status" class="form-input">
                <option value="open">Open</option>
                <option value="closed">Closed</option>
                <option value="all">All, including voided</option>
            </select>
        </div>
    </div>

    @if ($this->positions->isEmpty())
        <div class="card">No option positions to show.</div>
    @else
        <div class="card overflow-x-auto p-0">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-3 font-medium">Option</th>
                        <th class="px-4 py-3 font-medium">Account</th>
                        <th class="px-4 py-3 font-medium">Opened</th>
                        <th class="px-4 py-3 text-right font-medium">Open / total</th>
                        <th class="px-4 py-3 text-right font-medium">Premium received</th>
                        <th class="px-4 py-3 text-right font-medium">Opening fees</th>
                        <th class="px-4 py-3 text-right font-medium">Days to expiry</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->positions as $position)
                        <tr wire:key="option-{{ $position->id }}" @class(['text-zinc-400 line-through dark:text-zinc-500' => $position->isVoided()])>
                            <td class="px-4 py-3 font-medium">{{ $position->description() }}</td>
                            <td class="px-4 py-3">{{ $position->account->displayName() }}</td>
                            <td class="px-4 py-3">
                                {{ $position->opened_on->format('M j, Y') }}
                                <span class="text-xs text-zinc-500">{{ $position->origin->label() }}</span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $position->openContracts() }} / {{ $position->contracts }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $position->premiumReceived()->format() }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $position->opening_fees->format() }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                @if ($position->isVoided())
                                    Voided
                                @elseif ($position->isOpen())
                                    {{ $this->daysToExpiry($position) }}
                                @else
                                    Closed
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('options.show', $position) }}" class="underline underline-offset-4" wire:navigate>View</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
