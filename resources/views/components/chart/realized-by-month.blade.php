{{--
    Realized P/L per month: a green bar up for a gain, a red bar down for a loss, and a blue line for the
    running total. Hovering shows the exact amounts; each bar opens the Realized P/L page for that month.
    Expects $rows from App\Support\DashboardCharts::realizedByMonth and $linkParams (the dashboard filters).
--}}
@props(['rows', 'linkParams' => []])

@php
    $width = 720;
    $height = 260;
    $left = 76;
    $right = 16;
    $top = 16;
    $bottom = $height - 40;
    $scale = App\Support\ChartScale::for(array_merge(...array_map(fn (array $row) => [$row['total']->cents, $row['cumulative']->cents], $rows)), $top, $bottom);
    $slot = ($width - $left - $right) / max(count($rows), 1);
    $barWidth = min(36, $slot * 0.6);
    $labelEvery = (int) ceil(count($rows) / 12);
    $zero = $scale->y(0);
    $points = [];

    foreach ($rows as $index => $row) {
        $points[] = round($left + $slot * ($index + 0.5), 1).','.round($scale->y($row['cumulative']->cents), 1);
    }

    $describe = fn (array $row) => $row['month']->format('F Y').': '.($row['total']->isNegative() ? 'loss ' : ($row['total']->isZero() ? '' : 'gain ')).$row['total']->format()
        .' (stocks '.$row['stocks']->format().', options '.$row['options']->format().', dividends '.$row['dividends']->format().'). Running total '.$row['cumulative']->format().'.';
@endphp

<div class="flex flex-col gap-3">
    <div class="flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-zinc-600 dark:text-zinc-400" aria-hidden="true">
        <span class="flex items-center gap-1.5"><span class="size-3 rounded-sm bg-green-600 dark:bg-green-500"></span>Gain in the month ▲</span>
        <span class="flex items-center gap-1.5"><span class="size-3 rounded-sm bg-red-600 dark:bg-red-500"></span>Loss in the month ▼</span>
        <span class="flex items-center gap-1.5"><span class="h-0.5 w-4 bg-blue-600 dark:bg-blue-400"></span>Running total for the period</span>
    </div>

    <div class="overflow-x-auto">
        <svg viewBox="0 0 {{ $width }} {{ $height }}" class="w-full min-w-[560px] text-[11px]" role="img" aria-label="Realized profit and loss by month, with the running total">
            @foreach ($scale->ticks as $tick)
                <line x1="{{ $left }}" x2="{{ $width - $right }}" y1="{{ $scale->y($tick) }}" y2="{{ $scale->y($tick) }}" @class(['stroke-zinc-400 dark:stroke-zinc-500' => $tick === 0, 'stroke-zinc-200 dark:stroke-zinc-800' => $tick !== 0]) stroke-width="1" />
                <text x="{{ $left - 8 }}" y="{{ $scale->y($tick) + 4 }}" text-anchor="end" class="fill-zinc-500 dark:fill-zinc-400">{{ App\Support\ChartScale::dollars($tick) }}</text>
            @endforeach

            @foreach ($rows as $index => $row)
                @php
                    $center = $left + $slot * ($index + 0.5);
                    $valueY = $scale->y($row['total']->cents);
                    $url = route('realized.index', [...$linkParams, 'year' => 'custom', 'from' => $row['month']->format('Y-m-d'), 'to' => $row['month']->endOfMonth()->format('Y-m-d')]);
                @endphp
                <a href="{{ $url }}" wire:navigate>
                    <title>{{ $describe($row) }}</title>
                    <rect x="{{ $center - $slot / 2 }}" y="{{ $top }}" width="{{ $slot }}" height="{{ $bottom - $top }}" class="fill-transparent hover:fill-zinc-100 dark:hover:fill-zinc-800/60" />
                    @unless ($row['total']->isZero())
                        <rect
                            x="{{ $center - $barWidth / 2 }}"
                            y="{{ min($zero, $valueY) }}"
                            width="{{ $barWidth }}"
                            height="{{ max(abs($zero - $valueY), 1) }}"
                            rx="2"
                            @class(['fill-green-600 dark:fill-green-500' => ! $row['total']->isNegative(), 'fill-red-600 dark:fill-red-500' => $row['total']->isNegative()])
                        />
                    @endunless
                </a>
                @if ($index % $labelEvery === 0)
                    <text x="{{ $center }}" y="{{ $bottom + 16 }}" text-anchor="middle" class="fill-zinc-600 dark:fill-zinc-400">{{ $row['month']->format('M') }}</text>
                    @if ($index === 0 || $row['month']->month === 1)
                        <text x="{{ $center }}" y="{{ $bottom + 30 }}" text-anchor="middle" class="fill-zinc-400 dark:fill-zinc-500">{{ $row['month']->format('Y') }}</text>
                    @endif
                @endif
            @endforeach

            <polyline points="{{ implode(' ', $points) }}" fill="none" stroke-width="2" stroke-linejoin="round" class="pointer-events-none stroke-blue-600 dark:stroke-blue-400" />
            @foreach ($rows as $index => $row)
                <circle cx="{{ $left + $slot * ($index + 0.5) }}" cy="{{ $scale->y($row['cumulative']->cents) }}" r="3.5" class="fill-white stroke-blue-600 dark:fill-zinc-900 dark:stroke-blue-400" stroke-width="2">
                    <title>Running total at the end of {{ $row['month']->format('F Y') }}: {{ $row['cumulative']->format() }}</title>
                </circle>
            @endforeach
        </svg>
    </div>

    <details class="text-sm">
        <summary class="link w-fit text-xs">Show the numbers</summary>
        <div class="mt-2 overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th class="text-right">Stocks</th>
                        <th class="text-right">Options</th>
                        <th class="text-right">Dividends</th>
                        <th class="text-right">Month total</th>
                        <th class="text-right">Running total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr wire:key="realized-month-{{ $row['month']->format('Y-m') }}">
                            <td>{{ $row['month']->format('F Y') }}</td>
                            <td class="num"><x-money :amount="$row['stocks']" /></td>
                            <td class="num"><x-money :amount="$row['options']" /></td>
                            <td class="num"><x-money :amount="$row['dividends']" /></td>
                            <td class="num font-semibold"><x-money :amount="$row['total']" /></td>
                            <td class="num"><x-money :amount="$row['cumulative']" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
</div>
