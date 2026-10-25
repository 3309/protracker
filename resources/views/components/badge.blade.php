{{-- A small status label: green, red, amber, blue or gray. The text carries the meaning; color only supports it. --}}
@props(['tone' => 'gray'])

<span {{ $attributes->class([
    'inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
    'bg-green-50 text-green-800 ring-green-600/20 dark:bg-green-950 dark:text-green-300 dark:ring-green-400/30' => $tone === 'green',
    'bg-red-50 text-red-800 ring-red-600/20 dark:bg-red-950 dark:text-red-300 dark:ring-red-400/30' => $tone === 'red',
    'bg-amber-50 text-amber-800 ring-amber-600/30 dark:bg-amber-950 dark:text-amber-300 dark:ring-amber-400/30' => $tone === 'amber',
    'bg-blue-50 text-blue-800 ring-blue-600/20 dark:bg-blue-950 dark:text-blue-300 dark:ring-blue-400/30' => $tone === 'blue',
    'bg-zinc-100 text-zinc-700 ring-zinc-500/20 dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-400/30' => $tone === 'gray',
]) }}>{{ $slot }}</span>
