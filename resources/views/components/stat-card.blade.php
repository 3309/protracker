{{-- A dashboard summary: a label, a large value (the slot), an optional note, and a link to the details. --}}
@props(['label', 'icon' => null, 'href' => null, 'hint' => null, 'warning' => null])

@php
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" wire:navigate @endif
    {{ $attributes->class([
        'card flex flex-col gap-1 p-5',
        'transition hover:border-blue-300 hover:shadow-sm dark:hover:border-blue-700' => $href,
        'border-red-300 dark:border-red-800' => $warning,
    ]) }}
>
    <div class="flex items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400">
        @if ($icon)
            <x-icon :name="$icon" class="size-4" />
        @endif
        <span>{{ $label }}</span>
    </div>
    <div class="text-2xl font-semibold tracking-tight tabular-nums">{{ $slot }}</div>
    @if ($warning)
        <div class="flex items-center gap-1 text-xs font-medium text-red-700 dark:text-red-400">
            <x-icon name="warning" class="size-4" />
            {{ $warning }}
        </div>
    @endif
    @if ($hint)
        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $hint }}</div>
    @endif
</{{ $tag }}>
