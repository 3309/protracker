{{--
    Shows an amount with a consistent meaning, without changing it:
    - profit: a gain is green with + and ▲, a loss red with - and ▼, zero grey (P/L, dividends);
    - balance: a negative amount is red, zero grey (cash balances);
    - plain: no color (prices, premiums, fees, cost basis).
    A missing amount shows a dash.
--}}
@props(['amount', 'tone' => 'profit'])

@php
    $state = match (true) {
        $amount === null => 'missing',
        $amount->isZero() => 'zero',
        $amount->isNegative() => 'negative',
        default => 'positive',
    };
    $arrow = $tone === 'profit' ? ['positive' => '▲', 'negative' => '▼'][$state] ?? null : null;
    $text = match (true) {
        $amount === null => '—',
        $tone === 'profit' && $state === 'positive' => '+'.$amount->format(),
        default => $amount->format(),
    };
@endphp

<span {{ $attributes->class([
    'whitespace-nowrap tabular-nums',
    'text-zinc-500 dark:text-zinc-400' => $state === 'missing' || ($state === 'zero' && $tone !== 'plain'),
    'text-red-700 dark:text-red-400' => $state === 'negative' && $tone !== 'plain',
    'text-green-700 dark:text-green-400' => $state === 'positive' && $tone === 'profit',
]) }}>@if ($arrow)<span aria-hidden="true" class="me-0.5 text-[0.7em]">{{ $arrow }}</span>@endif{{ $text }}</span>
