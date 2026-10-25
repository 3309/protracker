{{-- Shown where a list has nothing in it yet. The slot is the message; "action" is an optional button or link. --}}
@props(['icon' => 'empty'])

<div {{ $attributes->class(['card flex flex-col items-center gap-3 px-6 py-10 text-center text-sm text-zinc-600 dark:text-zinc-400']) }}>
    <x-icon :name="$icon" class="size-8 text-zinc-400 dark:text-zinc-500" />
    <div>{{ $slot }}</div>
    @isset($action)
        <div>{{ $action }}</div>
    @endisset
</div>
