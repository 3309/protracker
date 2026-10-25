{{-- Shared fields for the add and edit account pages. Expects a Livewire component with a public AccountForm $form. --}}
<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label for="broker" class="form-label">Broker</label>
        <select id="broker" wire:model="form.broker" class="form-input">
            <option value="">Choose a broker…</option>
            @foreach (App\Enums\Broker::cases() as $broker)
                <option value="{{ $broker->value }}">{{ $broker->label() }}</option>
            @endforeach
        </select>
        @error('form.broker') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="nickname" class="form-label">Nickname</label>
        <input id="nickname" type="text" wire:model="form.nickname" class="form-input" placeholder="e.g. Main taxable">
        @error('form.nickname') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="account_type" class="form-label">Account type</label>
        <select id="account_type" wire:model="form.account_type" class="form-input">
            <option value="">Choose a type…</option>
            @foreach (App\Enums\AccountType::cases() as $accountType)
                <option value="{{ $accountType->value }}">{{ $accountType->label() }}</option>
            @endforeach
        </select>
        @error('form.account_type') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="account_number_last4" class="form-label">Last 4 digits of account number (optional)</label>
        <input id="account_number_last4" type="text" inputmode="numeric" maxlength="4" wire:model="form.account_number_last4" class="form-input">
        <p class="form-help">Only the last 4 digits are stored, never the full account number.</p>
        @error('form.account_number_last4') <p class="form-error">{{ $message }}</p> @enderror
    </div>
</div>

<fieldset class="flex flex-col gap-4">
    <legend class="text-base font-semibold">Default fees</legend>
    <p class="form-help -mt-2">
        These are suggestions used when you enter a trade. You can always override them with the actual fee from your broker statement.
    </p>

    <div class="grid gap-4 sm:grid-cols-3">
        <div>
            <label for="option_fee_per_contract" class="form-label">Option fee per contract ($)</label>
            <input id="option_fee_per_contract" type="text" inputmode="decimal" wire:model="form.option_fee_per_contract" class="form-input">
            @error('form.option_fee_per_contract') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="stock_fee_per_trade" class="form-label">Stock fee per trade ($)</label>
            <input id="stock_fee_per_trade" type="text" inputmode="decimal" wire:model="form.stock_fee_per_trade" class="form-input">
            @error('form.stock_fee_per_trade') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="assignment_fee" class="form-label">Assignment/exercise fee ($)</label>
            <input id="assignment_fee" type="text" inputmode="decimal" wire:model="form.assignment_fee" class="form-input">
            @error('form.assignment_fee') <p class="form-error">{{ $message }}</p> @enderror
        </div>
    </div>
</fieldset>
