{{-- Expects $accounts (active accounts, for a new movement) or $cashMovement (when editing). --}}
<div class="grid gap-4 sm:grid-cols-2">
    @isset($cashMovement)
        <div class="sm:col-span-2">
            <span class="form-label">Account</span>
            <p class="mt-1 text-sm">{{ $cashMovement->account->displayName() }}</p>
        </div>
    @else
        <div class="sm:col-span-2">
            <label for="account_id" class="form-label required">Account</label>
            <select id="account_id" wire:model="form.account_id" class="form-input">
                <option value="">Choose…</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}" wire:key="movement-account-{{ $account->id }}">{{ $account->displayName() }}</option>
                @endforeach
            </select>
            @error('form.account_id') <p class="form-error">{{ $message }}</p> @enderror
        </div>
    @endisset

    <div>
        <label for="movement_type" class="form-label required">Type</label>
        <select id="movement_type" wire:model="form.movement_type" class="form-input">
            <option value="">Choose…</option>
            @foreach (App\Enums\CashMovementType::cases() as $type)
                <option value="{{ $type->value }}">{{ $type->label() }} ({{ $type->isCredit() ? 'adds cash' : 'removes cash' }})</option>
            @endforeach
        </select>
        <p class="form-help">Deposits and withdrawals are never income. Interest and other items show in cash, not in realized profit.</p>
        @error('form.movement_type') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="amount" class="form-label required">Amount</label>
        <input id="amount" type="text" inputmode="decimal" wire:model="form.amount" class="form-input" placeholder="e.g. 1000.00">
        @error('form.amount') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="movement_date" class="form-label required">Date</label>
        <input id="movement_date" type="date" wire:model="form.movement_date" class="form-input">
        @error('form.movement_date') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="settlement_date" class="form-label">Settlement date (optional)</label>
        <input id="settlement_date" type="date" wire:model="form.settlement_date" class="form-input">
        <p class="form-help">Blank means the same day.</p>
        @error('form.settlement_date') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div class="sm:col-span-2">
        <label for="reference_note" class="form-label">Reference note (optional)</label>
        <input id="reference_note" type="text" wire:model="form.reference_note" class="form-input" placeholder="e.g. Transfer from checking">
        @error('form.reference_note') <p class="form-error">{{ $message }}</p> @enderror
    </div>
</div>
