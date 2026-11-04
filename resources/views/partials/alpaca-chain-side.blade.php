{{--
    The call or the put at one strike, in a phone-sized card: bid and ask first, then the rest of what Alpaca gave.
    Greeks Alpaca did not provide are left out. Expects $contract (or null) and $label ("Call" or "Put").
--}}
@php
    $figures = $contract ? App\Support\Alpaca\AlpacaFormat::contractFigures($contract) : [];
    $prices = array_intersect_key($figures, array_flip(['Bid', 'Ask', 'Last', 'IV']));
    $greeks = array_filter(array_intersect_key($figures, array_flip(['Delta', 'Gamma', 'Theta', 'Vega', 'Rho'])), fn (?string $value): bool => $value !== null);
@endphp

<div class="flex min-w-0 flex-col gap-1">
    <div class="text-xs font-semibold tracking-wide uppercase">{{ $label }}</div>
    @if ($contract)
        @include('partials.alpaca-figures', ['figures' => $prices])
        @if ($greeks !== [])
            @include('partials.alpaca-figures', ['figures' => $greeks])
        @else
            <p class="text-xs text-zinc-500 dark:text-zinc-400">Greeks not provided.</p>
        @endif
    @else
        <p class="text-xs text-zinc-500 dark:text-zinc-400">No {{ strtolower($label) }} at this strike.</p>
    @endif
</div>
