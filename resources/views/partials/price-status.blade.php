{{-- Expects $latest (Price|null) and a page with a "prices" PriceBook. --}}
@if ($latest === null)
    <span class="font-medium text-amber-700 dark:text-amber-400">Price needed</span>
@elseif ($this->prices->isStale($latest))
    <span class="font-medium text-amber-700 dark:text-amber-400">Stale ({{ $this->prices->ageInDays($latest) }} days old)</span>
@else
    Current
@endif
