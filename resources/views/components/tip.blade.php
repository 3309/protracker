{{-- A small "?" that explains a term when hovered or focused. --}}
@props(['text'])

<span tabindex="0" title="{{ $text }}" {{ $attributes->class(['inline-flex cursor-help align-middle text-zinc-400 hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300']) }}>
    <x-icon name="help" class="size-4" />
    <span class="sr-only">{{ $text }}</span>
</span>
