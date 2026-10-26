{{--
    Expects $accounts (active accounts, for a new trade) or $optionPosition (when editing). Set $isPurchase to true for an option
    bought to open, which also updates the cost summary as you type. Set $coveredCallCheck to true for a new sale, with
    $coveredCall from SellToOpenForm::coveredCallAvailability(), to offer only stocks with free shares for a call.
--}}
@php
    $isPurchase = $isPurchase ?? (isset($optionPosition) && $optionPosition->isLong());
    $coveredCallCheck = $coveredCallCheck ?? false;
    $coveredCall = $coveredCall ?? null;
    $summaryModel = ($isPurchase || $coveredCallCheck) && ! isset($optionPosition) ? 'wire:model.live.debounce.400ms' : 'wire:model';
    $liveModel = $coveredCallCheck ? 'wire:model.live' : 'wire:model';
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
            <label for="account_id" class="form-label required">Account</label>
            <select id="account_id" {{ $summaryModel }}="form.account_id" class="form-input">
                <option value="">Choose…</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}" wire:key="option-account-{{ $account->id }}">{{ $account->displayName() }} (fee {{ $account->option_fee_per_contract->format() }} / contract)</option>
                @endforeach
            </select>
            @error('form.account_id') <p class="form-error">{{ $message }}</p> @enderror
        </div>
    @endisset

    <h3 class="form-section-title border-t border-zinc-200 pt-4 sm:col-span-2 dark:border-zinc-800">The option</h3>

    <div>
        <label for="option_type" class="form-label required">Put or call ({{ $isPurchase ? 'bought' : 'sold' }})</label>
        <select id="option_type" {{ $liveModel }}="form.option_type" class="form-input">
            <option value="">Choose…</option>
            @foreach (App\Enums\OptionType::cases() as $type)
                <option value="{{ $type->value }}">{{ $type->label() }}</option>
            @endforeach
        </select>
        @error('form.option_type') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="underlying" class="form-label required">Ticker</label>
        @if ($coveredCall !== null && $coveredCall['choices'] !== [])
            <select id="underlying" wire:model.live="form.underlying" class="form-input">
                <option value="">Choose a stock you hold…</option>
                @foreach ($coveredCall['choices'] as $choice)
                    <option value="{{ $choice->ticker }}" wire:key="covered-call-{{ $choice->ticker }}">
                        {{ $choice->ticker }} — {{ $choice->available()->format() }} shares available (up to {{ $choice->maxContracts($coveredCall['multiplier']) }} {{ Str::plural('contract', $choice->maxContracts($coveredCall['multiplier'])) }})
                    </option>
                @endforeach
            </select>
            <p class="form-help">Only stocks in this account with at least {{ $coveredCall['multiplier'] }} shares not already covering another call on {{ $coveredCall['date']->format('M j, Y') }}.</p>
        @elseif ($coveredCall !== null)
            <p class="mt-1 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/60 dark:text-red-100">
                No stocks currently have enough available shares for a covered call in this account.
                <a href="{{ route('positions', ['account' => $this->form->account_id]) }}" class="link" wire:navigate>View stock holdings</a>            </p>
        @else
            <input id="underlying" type="text" {{ $liveModel }}="form.underlying" class="form-input uppercase" placeholder="e.g. AAPL">
        @endif
        @error('form.underlying') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    @if ($coveredCall !== null && $coveredCall['selected'] !== null)
        @php
            $selected = $coveredCall['selected'];
            $maxContracts = $selected->maxContracts($coveredCall['multiplier']);
            $requested = preg_match('/^\d{1,6}$/', $this->form->contracts) === 1 ? (int) $this->form->contracts : 0;
            $tone = match (true) {
                $maxContracts === 0 || $requested > $maxContracts => 'red',
                $requested === $maxContracts => 'amber',
                default => 'green',
            };
        @endphp
        <div @class([
            'rounded-lg border px-4 py-3 text-sm sm:col-span-2',
            'border-green-200 bg-green-50 text-green-900 dark:border-green-900 dark:bg-green-950/60 dark:text-green-100' => $tone === 'green',
            'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/60 dark:text-amber-100' => $tone === 'amber',
            'border-red-200 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950/60 dark:text-red-100' => $tone === 'red',
        ]) role="status">
            <p class="font-semibold">{{ $selected->ticker }} — {{ $accounts->firstWhere('id', $selected->accountId)?->displayName() }}, on {{ $selected->date->format('M j, Y') }}</p>
            <dl class="mt-2 grid grid-cols-[auto_auto] justify-start gap-x-6 gap-y-1">
                <dt>Eligible shares held</dt><dd class="num">{{ $selected->held->format() }}</dd>
                <dt>Shares committed to open calls</dt><dd class="num">{{ $selected->committed()->format() }} ({{ $selected->openContracts() }} {{ Str::plural('contract', $selected->openContracts()) }})</dd>
                <dt>Shares available</dt><dd class="num">{{ $selected->available()->format() }}</dd>
                <dt>Maximum additional covered calls</dt><dd class="num font-semibold">{{ $maxContracts }} {{ Str::plural('contract', $maxContracts) }}</dd>
            </dl>
            <p class="mt-2">
                @if ($maxContracts === 0)
                    Not enough free shares: these shares are already covering calls, or fewer than {{ $coveredCall['multiplier'] }} are held.
                @elseif ($requested > $maxContracts)
                    Too many contracts: only {{ $maxContracts }} can be covered.
                @elseif ($requested === $maxContracts)
                    This sale uses all the remaining free shares.
                @else
                    Enough free shares.
                @endif
            </p>
            @foreach ($selected->pastExpiration() as $stale)
                <p class="mt-2" wire:key="past-expiration-{{ $stale->id }}">
                    Still counted: {{ $stale->description() }} has passed its expiration with no expiry, assignment or buy-back recorded.
                    <a href="{{ route('options.show', $stale) }}" class="link" wire:navigate>Record what happened</a> to free its shares.
                </p>
            @endforeach
        </div>
    @endif

    <div>
        <label for="strike" class="form-label required">Strike price</label>
        <input id="strike" type="text" inputmode="decimal" wire:model="form.strike" class="form-input" placeholder="e.g. 50.00">
        @error('form.strike') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="expires_on" class="form-label required">Expiration date</label>
        <input id="expires_on" type="date" wire:model="form.expires_on" class="form-input">
        @error('form.expires_on') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <h3 class="form-section-title border-t border-zinc-200 pt-4 sm:col-span-2 dark:border-zinc-800">The trade</h3>

    <div>
        <label for="opened_on" class="form-label required">Trade date</label>
        <input id="opened_on" type="date" {{ $liveModel }}="form.opened_on" class="form-input">
        @if ($coveredCallCheck)
            <p class="form-help">For a call, the shares available are counted on this date (today while it is blank).</p>
        @endif
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
        <label for="contracts" class="form-label required">Contracts</label>
        @php($coveredMax = $coveredCall !== null && $coveredCall['selected'] !== null ? $coveredCall['selected']->maxContracts($coveredCall['multiplier']) : null)
        @if ($coveredMax !== null && $coveredMax >= 1 && $coveredMax <= 500)
            <select id="contracts" wire:model.live="form.contracts" class="form-input">
                <option value="">Choose…</option>
                @for ($count = 1; $count <= $coveredMax; $count++)
                    <option value="{{ $count }}" wire:key="contracts-{{ $count }}">{{ $count }}</option>
                @endfor
            </select>
            <p class="form-help">Up to {{ $coveredMax }} {{ Str::plural('contract', $coveredMax) }} can be covered by the free shares.</p>
        @else
            <input id="contracts" type="number" min="1" step="1" @if ($coveredMax !== null) max="{{ max($coveredMax, 1) }}" @endif {{ $summaryModel }}="form.contracts" class="form-input" placeholder="e.g. 1">
        @endif
        @error('form.contracts') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="premium_per_share" class="form-label required">Premium per share</label>
        <input id="premium_per_share" type="text" inputmode="decimal" {{ $summaryModel }}="form.premium_per_share" class="form-input" placeholder="e.g. 1.20">
        <p class="form-help">The price you {{ $isPurchase ? 'paid' : 'sold at' }}, e.g. 1.20 means $120 for one 100-share contract.</p>
        @error('form.premium_per_share') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="multiplier" class="form-label required">Shares per contract</label>
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

    <p class="form-help sm:col-span-2"><span class="text-red-600 dark:text-red-400">*</span> Required</p>
</div>
