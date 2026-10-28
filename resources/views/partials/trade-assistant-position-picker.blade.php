{{--
    Choose the recorded option an AI Trade Assistant proposal applies to. Expects $candidates (the open positions that
    match, from PositionMatcher) and $chosen (the chosen position or null). Binds to "position_id".
--}}
<fieldset class="flex flex-col gap-2">
    <legend class="form-label required">Option position</legend>

    @forelse ($candidates as $candidate)
        <label
            wire:key="candidate-{{ $candidate->id }}"
            @class([
                'flex cursor-pointer items-start gap-3 rounded-md border px-3 py-2 text-sm',
                'border-blue-500 bg-blue-50 dark:border-blue-400 dark:bg-blue-950/60' => $chosen?->is($candidate),
                'border-zinc-200 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800/50' => ! $chosen?->is($candidate),
            ])
        >
            <input type="radio" wire:model.live="position_id" value="{{ $candidate->id }}" class="mt-1">
            <span>
                <span class="font-medium">{{ $candidate->description() }}</span>
                <span class="block muted">
                    {{ $candidate->account->displayName() }} · {{ $candidate->openContracts() }} of {{ $candidate->contracts }} {{ Str::plural('contract', $candidate->contracts) }} open
                    · opened {{ $candidate->opened_on->format('M j, Y') }} at {{ $candidate->premium_per_share->format() }}
                </span>
            </span>
        </label>
    @empty
        <p class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/60 dark:text-red-100">
            No open option matches the ticker, put or call, strike, expiration and account in the description.
            Check those details and analyze again, or record it from the option's own page under
            <a href="{{ route('options.index') }}" class="link" wire:navigate>Open options</a>.
        </p>
    @endforelse

    @if ($candidates->count() > 1 && $chosen === null)
        <p class="form-help">Several open options match. Choose the one this applies to.</p>
    @endif
    @error('position_id') <p class="form-error">{{ $message }}</p> @enderror
</fieldset>
