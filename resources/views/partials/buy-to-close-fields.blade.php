{{-- Expects $prefix (the form property name, e.g. "close" or "form") and $feePerContract (Money). Set $isSale to true for selling a bought option to close. --}}
<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label for="{{ $prefix }}_event_date" class="form-label">Trade date</label>
        <input id="{{ $prefix }}_event_date" type="date" wire:model="{{ $prefix }}.event_date" class="form-input">
        @error($prefix.'.event_date') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="{{ $prefix }}_settlement_date" class="form-label">Settlement date (optional)</label>
        <input id="{{ $prefix }}_settlement_date" type="date" wire:model="{{ $prefix }}.settlement_date" class="form-input">
        <p class="form-help">Blank calculates the next trading day.</p>
        @error($prefix.'.settlement_date') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="{{ $prefix }}_contracts" class="form-label">{{ ($isSale ?? false) ? 'Contracts sold' : 'Contracts bought back' }}</label>
        <input id="{{ $prefix }}_contracts" type="number" min="1" step="1" wire:model="{{ $prefix }}.contracts" class="form-input">
        @error($prefix.'.contracts') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="{{ $prefix }}_price_per_share" class="form-label">{{ ($isSale ?? false) ? 'Sale premium per share' : 'Price per share' }}</label>
        <input id="{{ $prefix }}_price_per_share" type="text" inputmode="decimal" wire:model="{{ $prefix }}.price_per_share" class="form-input" placeholder="e.g. 0.40">
        @error($prefix.'.price_per_share') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="{{ $prefix }}_fees" class="form-label">Closing fee</label>
        <input id="{{ $prefix }}_fees" type="text" inputmode="decimal" wire:model="{{ $prefix }}.fees" class="form-input" placeholder="Leave blank to use the account default">
        <p class="form-help">Blank uses {{ $feePerContract->format() }} × contracts. Enter the actual fee if it differs.</p>
        @error($prefix.'.fees') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="{{ $prefix }}_reference_note" class="form-label">Reference note (optional)</label>
        <input id="{{ $prefix }}_reference_note" type="text" wire:model="{{ $prefix }}.reference_note" class="form-input">
        @error($prefix.'.reference_note') <p class="form-error">{{ $message }}</p> @enderror
    </div>
</div>
