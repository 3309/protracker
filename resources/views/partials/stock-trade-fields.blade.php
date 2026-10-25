{{-- Expects $accounts (active accounts, for a new trade) or $stockTrade (when editing). --}}
<div class="grid gap-4 sm:grid-cols-2">
    @isset($stockTrade)
        <div>
            <span class="form-label">Account</span>
            <p class="mt-1 text-sm">{{ $stockTrade->account->displayName() }}</p>
        </div>
        <div>
            <span class="form-label">Buy or sell</span>
            <p class="mt-1 text-sm">{{ $stockTrade->side->label() }}</p>
            <p class="form-help">To change the account or side, void this trade and enter a new one.</p>
        </div>
    @else
        <div>
            <label for="account_id" class="form-label required">Account</label>
            <select id="account_id" wire:model="form.account_id" class="form-input">
                <option value="">Choose…</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}" wire:key="trade-account-{{ $account->id }}">{{ $account->displayName() }}</option>
                @endforeach
            </select>
            @error('form.account_id') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="side" class="form-label required">Buy or sell</label>
            <select id="side" wire:model="form.side" class="form-input">
                <option value="">Choose…</option>
                @foreach (App\Enums\TradeSide::cases() as $side)
                    <option value="{{ $side->value }}">{{ $side->label() }}</option>
                @endforeach
            </select>
            @error('form.side') <p class="form-error">{{ $message }}</p> @enderror
        </div>
    @endisset

    <h3 class="form-section-title border-t border-zinc-200 pt-4 sm:col-span-2 dark:border-zinc-800">The trade</h3>

    <div>
        <label for="ticker" class="form-label required">Ticker</label>
        <input id="ticker" type="text" wire:model="form.ticker" class="form-input uppercase" placeholder="e.g. AAPL">
        @error('form.ticker') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="trade_date" class="form-label required">Trade date</label>
        <input id="trade_date" type="date" wire:model="form.trade_date" class="form-input">
        @error('form.trade_date') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="shares" class="form-label required">Shares</label>
        <input id="shares" type="text" inputmode="decimal" wire:model="form.shares" class="form-input" placeholder="e.g. 100 or 0.5">
        @error('form.shares') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="price_per_share" class="form-label required">Price per share</label>
        <input id="price_per_share" type="text" inputmode="decimal" wire:model="form.price_per_share" class="form-input" placeholder="e.g. 52.00">
        @error('form.price_per_share') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="fees" class="form-label">Fee</label>
        <input id="fees" type="text" inputmode="decimal" wire:model="form.fees" class="form-input" placeholder="Leave blank to use the account default">
        <p class="form-help">Blank uses the account's default stock fee per trade. Enter the actual fee from your broker if it differs.</p>
        @error('form.fees') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="settlement_date" class="form-label">Settlement date (optional)</label>
        <input id="settlement_date" type="date" wire:model="form.settlement_date" class="form-input">
        <p class="form-help">
            Blank calculates the next trading day, skipping weekends and market holidays.
            @isset($stockTrade)
                @unless ($stockTrade->settlement_date_overridden)
                    Currently {{ $stockTrade->settlement_date->format('M j, Y') }} (calculated).
                @endunless
            @endisset
        </p>
        @error('form.settlement_date') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div class="sm:col-span-2">
        <label for="reference_note" class="form-label">Reference note (optional)</label>
        <input id="reference_note" type="text" wire:model="form.reference_note" class="form-input" placeholder="e.g. Order number or statement page">
        @error('form.reference_note') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <p class="form-help sm:col-span-2"><span class="text-red-600 dark:text-red-400">*</span> Required</p>
</div>
