{{-- Expects $feePerContract (Money): the account's default option fee per contract. --}}
<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label for="underlying" class="form-label">Ticker</label>
        <input id="underlying" type="text" wire:model="form.underlying" class="form-input uppercase" placeholder="e.g. AAPL">
        @error('form.underlying') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="option_type" class="form-label">Put or call (sold)</label>
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
        <label for="opened_on" class="form-label">Date opened (sold)</label>
        <input id="opened_on" type="date" wire:model="form.opened_on" class="form-input">
        @error('form.opened_on') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="contracts" class="form-label">Contracts still open</label>
        <input id="contracts" type="number" min="1" step="1" wire:model="form.contracts" class="form-input" placeholder="e.g. 1">
        <p class="form-help">If some contracts were closed before the reporting start date, enter only the ones still open.</p>
        @error('form.contracts') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="premium_per_share" class="form-label">Premium received per share</label>
        <input id="premium_per_share" type="text" inputmode="decimal" wire:model="form.premium_per_share" class="form-input" placeholder="e.g. 1.20">
        <p class="form-help">The price per share you sold at, e.g. 1.20 means $120 for one 100-share contract.</p>
        @error('form.premium_per_share') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="multiplier" class="form-label">Shares per contract</label>
        <input id="multiplier" type="number" min="1" step="1" wire:model="form.multiplier" class="form-input">
        <p class="form-help">Normally 100. Change it only for an adjusted contract.</p>
        @error('form.multiplier') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="opening_fees" class="form-label">Opening fees (for the contracts still open)</label>
        <input id="opening_fees" type="text" inputmode="decimal" wire:model="form.opening_fees" class="form-input" placeholder="Leave blank to use the account default">
        <p class="form-help">Blank uses this account's default of {{ $feePerContract->format() }} × contracts. Enter the actual fee from your broker if you know it.</p>
        @error('form.opening_fees') <p class="form-error">{{ $message }}</p> @enderror
    </div>
</div>

@include('partials.opening-source-fields', ['sourceField' => 'form.source', 'noteField' => 'form.reference_note', 'idPrefix' => 'option'])
