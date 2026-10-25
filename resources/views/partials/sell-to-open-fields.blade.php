{{-- Expects $accounts (active accounts, for a new trade) or $optionPosition (when editing). Set $isPurchase to true for an option bought to open, which also updates the cost summary as you type. --}}
@php
    $isPurchase = $isPurchase ?? (isset($optionPosition) && $optionPosition->isLong());
    $summaryModel = $isPurchase && ! isset($optionPosition) ? 'wire:model.live.debounce.400ms' : 'wire:model';
@endphp
<div class="grid gap-4 sm:grid-cols-2">
    @isset($optionPosition)
        <div class="sm:col-span-2">
            <span class="form-label">Account</span>
            <p class="mt-1 text-sm">{{ $optionPosition->account->displayName() }}</p>
            <p class="form-help">To change the account, void this position and enter a new one.</p>
        </div>
    @else
        <div class="sm:col-span-2">
            <label for="account_id" class="form-label">Account</label>
            <select id="account_id" {{ $summaryModel }}="form.account_id" class="form-input">
                <option value="">Choose…</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}" wire:key="option-account-{{ $account->id }}">{{ $account->displayName() }} (fee {{ $account->option_fee_per_contract->format() }} / contract)</option>
                @endforeach
            </select>
            @error('form.account_id') <p class="form-error">{{ $message }}</p> @enderror
        </div>
    @endisset

    <div>
        <label for="underlying" class="form-label">Ticker</label>
        <input id="underlying" type="text" wire:model="form.underlying" class="form-input uppercase" placeholder="e.g. AAPL">
        @error('form.underlying') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="option_type" class="form-label">Put or call ({{ $isPurchase ? 'bought' : 'sold' }})</label>
        <select id="option_type" wire:model="form.option_type" class="form-input">
            <option value="">Choose…</option>
            @foreach (App\Enums\OptionType::cases() as $type)
                <option value="{{ $type->value }}">{{ $type->label() }}</option>
            @endforeach
        </select>
        @error('form.option_type') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="strike" class="form-label">Strike price</label>
        <input id="strike" type="text" inputmode="decimal" wire:model="form.strike" class="form-input" placeholder="e.g. 50.00">
        @error('form.strike') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="expires_on" class="form-label">Expiration date</label>
        <input id="expires_on" type="date" wire:model="form.expires_on" class="form-input">
        @error('form.expires_on') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="opened_on" class="form-label">Trade date</label>
        <input id="opened_on" type="date" wire:model="form.opened_on" class="form-input">
        @error('form.opened_on') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="settlement_date" class="form-label">Settlement date (optional)</label>
        <input id="settlement_date" type="date" wire:model="form.settlement_date" class="form-input">
        <p class="form-help">
            Blank calculates the next trading day.
            @if (isset($optionPosition) && ! $optionPosition->opening_settlement_date_overridden && $optionPosition->opening_settlement_date)
                Currently {{ $optionPosition->opening_settlement_date->format('M j, Y') }} (calculated).
            @endif
        </p>
        @error('form.settlement_date') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="contracts" class="form-label">Contracts</label>
        <input id="contracts" type="number" min="1" step="1" {{ $summaryModel }}="form.contracts" class="form-input" placeholder="e.g. 1">
        @error('form.contracts') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="premium_per_share" class="form-label">Premium per share</label>
        <input id="premium_per_share" type="text" inputmode="decimal" {{ $summaryModel }}="form.premium_per_share" class="form-input" placeholder="e.g. 1.20">
        <p class="form-help">The price you {{ $isPurchase ? 'paid' : 'sold at' }}, e.g. 1.20 means $120 for one 100-share contract.</p>
        @error('form.premium_per_share') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="multiplier" class="form-label">Shares per contract</label>
        <input id="multiplier" type="number" min="1" step="1" {{ $summaryModel }}="form.multiplier" class="form-input">
        <p class="form-help">Normally 100. Change it only for an adjusted contract.</p>
        @error('form.multiplier') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="opening_fees" class="form-label">Opening fee</label>
        <input id="opening_fees" type="text" inputmode="decimal" {{ $summaryModel }}="form.opening_fees" class="form-input" placeholder="Leave blank to use the account default">
        <p class="form-help">Blank uses the account's default fee per contract × contracts. Enter the actual fee if it differs.</p>
        @error('form.opening_fees') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div class="sm:col-span-2">
        <label for="reference_note" class="form-label">Reference note (optional)</label>
        <input id="reference_note" type="text" wire:model="form.reference_note" class="form-input" placeholder="e.g. Order number">
        @error('form.reference_note') <p class="form-error">{{ $message }}</p> @enderror
    </div>
</div>
