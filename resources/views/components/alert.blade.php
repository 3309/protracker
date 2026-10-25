{{--
    A message box with an icon and a heading, so its meaning does not depend on color:
    critical (red), warning (amber), info (blue) or success (green).
    A dismissible alert is hidden in this browser tab only; nothing in the records changes.
--}}
@props(['level' => 'info', 'title' => null, 'dismissKey' => null])

@php
    $icon = ['critical' => 'critical', 'warning' => 'warning', 'info' => 'info', 'success' => 'success'][$level];
@endphp

<div
    role="{{ $level === 'critical' ? 'alert' : 'status' }}"
    @if ($dismissKey)
        x-data="{ shown: ! window.isAlertDismissed(@js($dismissKey)) }"
        x-show="shown"
    @endif
    {{ $attributes->class([
        'flex items-start gap-3 rounded-lg border px-4 py-3 text-sm',
        'border-red-200 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950/60 dark:text-red-100' => $level === 'critical',
        'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/60 dark:text-amber-100' => $level === 'warning',
        'border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-900 dark:bg-blue-950/60 dark:text-blue-100' => $level === 'info',
        'border-green-200 bg-green-50 text-green-900 dark:border-green-900 dark:bg-green-950/60 dark:text-green-100' => $level === 'success',
    ]) }}
>
    <x-icon :name="$icon" @class([
        'mt-0.5',
        'text-red-600 dark:text-red-400' => $level === 'critical',
        'text-amber-600 dark:text-amber-400' => $level === 'warning',
        'text-blue-600 dark:text-blue-400' => $level === 'info',
        'text-green-600 dark:text-green-400' => $level === 'success',
    ]) />
    <div class="flex min-w-0 flex-1 flex-col gap-1">
        @if ($title)
            <div class="font-semibold">{{ $title }}</div>
        @endif
        <div>{{ $slot }}</div>
    </div>
    @if ($dismissKey)
        <button type="button" x-on:click="shown = false; window.dismissAlert(@js($dismissKey))" class="-m-1 rounded p-1 opacity-70 hover:opacity-100" title="Hide this alert for now">
            <x-icon name="close" class="size-4" />
            <span class="sr-only">Hide this alert for now</span>
        </button>
    @endif
</div>
