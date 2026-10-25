<?php

use App\Models\AuditEntry;
use App\Models\Price;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Price')] class extends Component
{
    public Price $price;

    public string $reason = '';

    public function void(): void
    {
        abort_if($this->price->isVoided(), 403, 'This price is already voided.');

        $this->validate(
            ['reason' => ['required', 'string', 'max:1000']],
            ['reason.required' => 'Please explain why you are voiding this price. It is saved in the audit history.'],
        );

        $this->price->void($this->reason);
        $this->reset('reason');

        unset($this->auditEntries);

        session()->now('message', 'Price voided. The previous price for this stock or contract is used again.');
    }

    /**
     * @return Collection<int, AuditEntry>
     */
    #[Computed]
    public function auditEntries(): Collection
    {
        return $this->price->auditEntries()->with('user')->latest('id')->get();
    }

    #[Computed]
    public function displayTimezone(): string
    {
        return Setting::current()->display_timezone;
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="page-title">
                {{ $price->description() }}: {{ $price->price->format() }}
                @if ($price->isVoided())
                    <x-badge tone="gray" class="ms-2 align-middle">Voided</x-badge>
                @endif
            </h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">As of {{ $price->as_of->timezone($this->displayTimezone)->format('M j, Y g:i A T') }}{{ $price->reference_note ? ' · '.$price->reference_note : '' }}</p>
        </div>
        <a href="{{ route('prices.index') }}" class="btn-secondary" wire:navigate>Back to prices</a>
    </div>

    @if (session('message'))
        <x-alert level="success">{{ session('message') }}</x-alert>
    @endif

    @unless ($price->isVoided())
        <div class="card flex flex-col gap-4">
            <p class="text-sm">A mistaken price is voided rather than edited, then the right price is entered again. The history is kept.</p>
            <div>
                <label for="reason" class="form-label">Reason</label>
                <input id="reason" type="text" wire:model="reason" class="form-input" placeholder="e.g. Typed the wrong ticker">
                @error('reason') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <button type="button" wire:click="void" wire:confirm="Void this price?" class="btn-danger">Void price</button>
            </div>
        </div>
    @endunless

    @include('partials.audit-history', ['entries' => $this->auditEntries, 'timezone' => $this->displayTimezone])
</div>
