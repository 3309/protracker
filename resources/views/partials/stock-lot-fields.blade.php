<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label for="ticker" class="form-label required">Ticker</label>
        <input id="ticker" type="text" wire:model="form.ticker" class="form-input uppercase" placeholder="e.g. AAPL">
        @error('form.ticker') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="acquired_on" class="form-label required">Original purchase date</label>
        <input id="acquired_on" type="date" wire:model="form.acquired_on" class="form-input">
        <p class="form-help">The trade date of the original purchase. It decides which shares are sold first.</p>
        @error('form.acquired_on') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="shares" class="form-label required">Shares still held</label>
        <input id="shares" type="text" inputmode="decimal" wire:model="form.shares" class="form-input" placeholder="e.g. 100 or 0.5">
        @error('form.shares') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="cost_per_share" class="form-label required">Original cost per share</label>
        <input id="cost_per_share" type="text" inputmode="decimal" wire:model="form.cost_per_share" class="form-input" placeholder="e.g. 50.00">
        <p class="form-help">The actual purchase price, without option premium (Method B).</p>
        @error('form.cost_per_share') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="fees" class="form-label">Purchase fees</label>
        <input id="fees" type="text" inputmode="decimal" wire:model="form.fees" class="form-input">
        <p class="form-help">Kept separate from the cost per share. Leave at 0.00 if there were none or you don't know them.</p>
        @error('form.fees') <p class="form-error">{{ $message }}</p> @enderror
    </div>
</div>

@include('partials.opening-source-fields', ['sourceField' => 'form.source', 'noteField' => 'form.reference_note', 'idPrefix' => 'lot'])
