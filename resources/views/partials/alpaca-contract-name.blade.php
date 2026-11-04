{{--
    What an order or position is. An option reads "AAPL $300 Put · Nov 20, 2026" with its side and contract count below;
    a multi-leg order lists the legs the service sent. Expects $symbol, $side, $quantity and optionally $legs.
--}}
@php
    $isOption = App\Support\Alpaca\AlpacaFormat::optionSymbol($symbol) !== null;
    $legs = array_filter($legs ?? [], is_array(...));
@endphp

<div class="font-semibold">{{ $symbol !== '' ? App\Support\Alpaca\AlpacaFormat::symbolLabel($symbol) : 'Multi-leg' }}</div>
@if ($isOption && App\Support\Alpaca\AlpacaFormat::contractsLabel($side, $quantity))
    <div class="text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ App\Support\Alpaca\AlpacaFormat::contractsLabel($side, $quantity) }}</div>
@endif
@foreach ($legs as $leg)
    @if (is_string($leg['symbol'] ?? null))
        <div class="text-xs font-normal text-zinc-500 dark:text-zinc-400">
            {{ App\Support\Alpaca\AlpacaFormat::symbolLabel($leg['symbol']) }}@if (App\Support\Alpaca\AlpacaFormat::contractsLabel($leg['side'] ?? null, $leg['qty'] ?? $leg['ratio_qty'] ?? null)): {{ App\Support\Alpaca\AlpacaFormat::contractsLabel($leg['side'] ?? null, $leg['qty'] ?? $leg['ratio_qty'] ?? null) }}@endif
        </div>
    @endif
@endforeach
