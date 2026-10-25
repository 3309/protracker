{{-- Expects $sourceField and $noteField (wire:model paths) and $idPrefix (unique element id prefix). --}}
<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label for="{{ $idPrefix }}_source" class="form-label">Source</label>
        <select id="{{ $idPrefix }}_source" wire:model="{{ $sourceField }}" class="form-input">
            <option value="">Choose…</option>
            @foreach (App\Enums\OpeningBalanceSource::cases() as $source)
                <option value="{{ $source->value }}">{{ $source->label() }}</option>
            @endforeach
        </select>
        <p class="form-help">Where this figure came from.</p>
        @error($sourceField) <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="{{ $idPrefix }}_note" class="form-label">Reference note (optional)</label>
        <input id="{{ $idPrefix }}_note" type="text" wire:model="{{ $noteField }}" class="form-input" placeholder="e.g. Fidelity Dec 2025 statement, page 3">
        @error($noteField) <p class="form-error">{{ $message }}</p> @enderror
    </div>
</div>
