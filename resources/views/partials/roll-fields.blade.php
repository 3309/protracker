{{-- Expects $optionPosition, the position being rolled. --}}
<p class="text-sm text-zinc-600 dark:text-zinc-400">
    Buy back contracts of this option and sell a new {{ $optionPosition->underlying }} {{ strtolower($optionPosition->option_type->label()) }}
    on the same day. Blank fees use {{ $optionPosition->account->option_fee_per_contract->format() }} × contracts.
</p>

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label for="roll_event_date" class="form-label required">Roll date</label>
        <input id="roll_event_date" type="date" wire:model="roll.event_date" class="form-input">
        @error('roll.event_date') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div></div>

    <fieldset class="flex flex-col gap-4 rounded-md border border-zinc-200 p-4 dark:border-zinc-700">
        <legend class="px-1 text-sm font-medium">Buy back</legend>

        <div>
            <label for="roll_contracts" class="form-label required">Contracts bought back</label>
            <input id="roll_contracts" type="number" min="1" step="1" wire:model="roll.contracts" class="form-input">
            @error('roll.contracts') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="roll_price_per_share" class="form-label required">Buy-back price per share</label>
            <input id="roll_price_per_share" type="text" inputmode="decimal" wire:model="roll.price_per_share" class="form-input" placeholder="e.g. 1.50">
            @error('roll.price_per_share') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="roll_closing_fees" class="form-label">Closing fee</label>
            <input id="roll_closing_fees" type="text" inputmode="decimal" wire:model="roll.closing_fees" class="form-input" placeholder="Blank uses the account default">
            @error('roll.closing_fees') <p class="form-error">{{ $message }}</p> @enderror
        </div>
    </fieldset>

    <fieldset class="flex flex-col gap-4 rounded-md border border-zinc-200 p-4 dark:border-zinc-700">
        <legend class="px-1 text-sm font-medium">New option sold</legend>

        <div>
            <label for="roll_new_strike" class="form-label required">New strike</label>
            <input id="roll_new_strike" type="text" inputmode="decimal" wire:model="roll.new_strike" class="form-input">
            @error('roll.new_strike') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="roll_new_expires_on" class="form-label required">New expiration date</label>
            <input id="roll_new_expires_on" type="date" wire:model="roll.new_expires_on" class="form-input">
            @error('roll.new_expires_on') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="roll_new_contracts" class="form-label required">New contracts</label>
            <input id="roll_new_contracts" type="number" min="1" step="1" wire:model="roll.new_contracts" class="form-input">
            @error('roll.new_contracts') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="roll_new_premium_per_share" class="form-label required">New premium per share</label>
            <input id="roll_new_premium_per_share" type="text" inputmode="decimal" wire:model="roll.new_premium_per_share" class="form-input" placeholder="e.g. 1.60">
            @error('roll.new_premium_per_share') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="roll_new_opening_fees" class="form-label">New opening fee</label>
            <input id="roll_new_opening_fees" type="text" inputmode="decimal" wire:model="roll.new_opening_fees" class="form-input" placeholder="Blank uses the account default">
            @error('roll.new_opening_fees') <p class="form-error">{{ $message }}</p> @enderror
        </div>
    </fieldset>

    <div class="sm:col-span-2">
        <label for="roll_reference_note" class="form-label">Reference note (optional)</label>
        <input id="roll_reference_note" type="text" wire:model="roll.reference_note" class="form-input">
        @error('roll.reference_note') <p class="form-error">{{ $message }}</p> @enderror
    </div>
</div>
