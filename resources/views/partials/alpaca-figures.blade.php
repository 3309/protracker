{{-- Labelled values in a compact grid, for the phone-sized cards. Expects $figures (label => text, null shows a dash). --}}
<dl class="grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
    @foreach ($figures as $label => $value)
        <div class="min-w-0">
            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
            <dd class="tabular-nums">{{ $value ?? '—' }}</dd>
        </div>
    @endforeach
</dl>
