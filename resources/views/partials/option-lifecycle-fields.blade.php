{{-- Expects $prefix (the form property name), $optionPosition and $isAssignment. --}}
<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label for="{{ $prefix }}_event_date" class="form-label required">{{ $isAssignment ? 'Assignment date' : 'Expiry date' }}</label>
        <input id="{{ $prefix }}_event_date" type="date" wire:model="{{ $prefix }}.event_date" class="form-input">
        <p class="form-help">
            Use the date your broker shows. It may be up to 7 days after the expiration date ({{ $optionPosition->expires_on->format('M j, Y') }}).
            @if ($isAssignment)
                An earlier date records an early assignment.
            @endif
        </p>
        @error($prefix.'.event_date') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="{{ $prefix }}_contracts" class="form-label required">Contracts</label>
        <input id="{{ $prefix }}_contracts" type="number" min="1" step="1" wire:model="{{ $prefix }}.contracts" class="form-input">
        @error($prefix.'.contracts') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    @if ($isAssignment)
        <div>
            <label for="{{ $prefix }}_fees" class="form-label">Assignment fee</label>
            <input id="{{ $prefix }}_fees" type="text" inputmode="decimal" wire:model="{{ $prefix }}.fees" class="form-input" placeholder="Leave blank to use the account default">
            <p class="form-help">Blank uses the account's assignment fee ({{ $optionPosition->account->assignment_fee->format() }}). It is charged on the stock trade.</p>
            @error($prefix.'.fees') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div class="text-sm">
            <span class="form-label">Stock trade that will be recorded</span>
            <p class="mt-1">
                {{ $optionPosition->option_type === App\Enums\OptionType::Put ? 'Buy' : 'Sell' }} contracts × {{ $optionPosition->multiplier }} {{ $optionPosition->underlying }} shares at the strike, {{ $optionPosition->strike->format() }}.
                @if ($optionPosition->option_type === App\Enums\OptionType::Call)
                    The oldest shares are sold first.
                @endif
            </p>
        </div>
    @endif

    <div class="sm:col-span-2">
        <label for="{{ $prefix }}_reference_note" class="form-label">Reference note (optional)</label>
        <input id="{{ $prefix }}_reference_note" type="text" wire:model="{{ $prefix }}.reference_note" class="form-input">
        @error($prefix.'.reference_note') <p class="form-error">{{ $message }}</p> @enderror
    </div>
</div>
