{{--
    The top of an AI Trade Assistant review panel: what the AI found for each field and how sure it was, its questions,
    the totals for the values as they stand, the warnings, and the fields still to fill in.
    Expects $proposal (TradeProposal), $title, $fields (proposal field keys to list), $totals (label => Money|null),
    $warnings and $missing (lists of text). $labels may rename fields for this kind of transaction (field => label).
--}}
@php
    $labels = [...App\Support\TradeAssistant\TradeProposal::FIELDS, ...($labels ?? [])];
    $statuses = [
        'stated' => ['Stated', 'green'],
        'derived' => ['Worked out', 'blue'],
        'selected' => ['Your account choice', 'blue'],
        'missing' => ['Missing', 'red'],
        'ambiguous' => ['Unclear', 'amber'],
    ];
@endphp

<div class="flex flex-col gap-4">
    <div>
        <h2 class="section-title">{{ $title }}</h2>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            Check every value below. Nothing is saved until you press Confirm and save.
        </p>
    </div>

    <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
        @foreach ($fields as $field)
            @php [$statusLabel, $tone] = $statuses[$proposal->status($field)] ?? $statuses['missing']; @endphp
            <div class="flex items-center justify-between gap-3 border-b border-zinc-100 py-1.5 dark:border-zinc-800" wire:key="finding-{{ $field }}">
                <dt class="text-zinc-500 dark:text-zinc-400">{{ $labels[$field] }}</dt>
                <dd class="flex items-center gap-2 text-right">
                    <span class="font-medium tabular-nums">{{ $proposal->value($field) !== '' ? $proposal->value($field) : '—' }}</span>
                    <x-badge :tone="$tone">{{ $statusLabel }}</x-badge>
                </dd>
            </div>
        @endforeach
    </dl>

    @if ($proposal->questions !== [])
        <x-alert level="info" title="Questions from the assistant">
            <ul class="list-disc ps-5">
                @foreach ($proposal->questions as $question)
                    <li>{{ $question }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    @if ($totals !== [])
        <dl class="grid gap-3 rounded-md border border-zinc-200 bg-zinc-50 p-4 text-sm sm:grid-cols-3 dark:border-zinc-700 dark:bg-zinc-800/50" aria-live="polite">
            @foreach ($totals as $label => $amount)
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                    <dd class="font-medium tabular-nums">{{ $amount?->format() ?? '—' }}</dd>
                </div>
            @endforeach
        </dl>
    @endif

    @if ($warnings !== [])
        <x-alert level="warning" title="Please check">
            <ul class="list-disc ps-5">
                @foreach ($warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    @if ($missing !== [])
        <x-alert level="critical" title="Still needed before saving">
            {{ implode(', ', $missing) }}.
        </x-alert>
    @endif

    @error('proposal')
        <x-alert level="critical">{{ $message }}</x-alert>
    @enderror
</div>
