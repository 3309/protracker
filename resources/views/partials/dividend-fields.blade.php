{{-- Expects $accounts (active accounts, for a new dividend) or $dividend (when editing). --}}
<div class="grid gap-4 sm:grid-cols-2">
    @isset($dividend)
        <div class="sm:col-span-2">
            <span class="form-label">Account</span>
            <p class="mt-1 text-sm">{{ $dividend->account->displayName() }}</p>
        </div>
    @else
        <div class="sm:col-span-2">
            <label for="account_id" class="form-label required">Account</label>
            <select id="account_id" wire:model="form.account_id" class="form-input">
                <option value="">Choose…</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}" wire:key="dividend-account-{{ $account->id }}">{{ $account->displayName() }}</option>
                @endforeach
            </select>
            @error('form.account_id') <p class="form-error">{{ $message }}</p> @enderror
        </div>
    @endisset

    <div>
        <label for="ticker" class="form-label required">Ticker</label>
        <input id="ticker" type="text" wire:model="form.ticker" class="form-input uppercase" placeholder="e.g. KO">
        @error('form.ticker') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="amount_per_share" class="form-label required">Amount per share</label>
        <input id="amount_per_share" type="text" inputmode="decimal" wire:model="form.amount_per_share" class="form-input" placeholder="e.g. 0.50">
        <p class="form-help">Up to six decimal places, e.g. 0.123456.</p>
        @error('form.amount_per_share') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="ex_date" class="form-label">Ex-dividend date</label>
        <input id="ex_date" type="date" wire:model="form.ex_date" class="form-input">
        <p class="form-help">Shares bought on or after this date, or sold before it, do not get the dividend.</p>
        @error('form.ex_date') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="pay_date" class="form-label required">Pay date</label>
        <input id="pay_date" type="date" wire:model="form.pay_date" class="form-input">
        <p class="form-help">The dividend counts as income and cash on this date once confirmed.</p>
        @error('form.pay_date') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div class="sm:col-span-2">
        <label for="reference_note" class="form-label">Reference note (optional)</label>
        <input id="reference_note" type="text" wire:model="form.reference_note" class="form-input">
        @error('form.reference_note') <p class="form-error">{{ $message }}</p> @enderror
    </div>
</div>
