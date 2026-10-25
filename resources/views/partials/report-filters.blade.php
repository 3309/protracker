{{-- Filters for pages extending App\Livewire\ReportPage. Set $showPeriod to false to hide the period controls. --}}
<div class="card flex flex-wrap items-end gap-4">
    <div>
        <label for="filter_account" class="form-label">Account</label>
        <select id="filter_account" wire:model.live="account" class="form-input">
            <option value="">All accounts</option>
            @foreach ($this->filterAccounts as $filterAccount)
                <option value="{{ $filterAccount->id }}" wire:key="filter-account-{{ $filterAccount->id }}">
                    {{ $filterAccount->displayName() }}{{ $filterAccount->isArchived() ? ' (archived)' : '' }}
                </option>
            @endforeach
        </select>
    </div>

    @if ($showPeriod ?? true)
        <div>
            <label for="filter_year" class="form-label">Period</label>
            <select id="filter_year" wire:model.live="year" class="form-input">
                @foreach ($this->availableYears as $availableYear)
                    <option value="{{ $availableYear }}" wire:key="filter-year-{{ $availableYear }}">{{ $availableYear }}</option>
                @endforeach
                <option value="custom">Custom dates</option>
            </select>
        </div>

        @if ($year === 'custom')
            <div>
                <label for="filter_from" class="form-label">From</label>
                <input id="filter_from" type="date" wire:model.live="from" class="form-input">
            </div>
            <div>
                <label for="filter_to" class="form-label">To</label>
                <input id="filter_to" type="date" wire:model.live="to" class="form-input">
            </div>
        @endif
    @endif
</div>

@if (($showPeriod ?? true) && $this->reportingPeriod === null)
    <div class="card text-sm text-red-600 dark:text-red-400">Choose a valid "from" and "to" date. The "to" date cannot be before the "from" date.</div>
@endif
