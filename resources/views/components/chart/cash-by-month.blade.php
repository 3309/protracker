{{--
    Total cash per broker on the first day of the period and at each month end (today for this month), from the
    cash ledger. Below zero is shaded red. Expects $chart from App\Support\DashboardCharts::cashByMonth.
--}}
@props(['chart'])

@php
    $width = 720;
    $height = 260;
    $left = 76;
    $right = 16;
    $top = 16;
    $bottom = $height - 40;
    $dates = $chart['dates'];
    $colors = [
        'fidelity' => ['stroke-blue-600 dark:stroke-blue-400', 'bg-blue-600 dark:bg-blue-400'],
        'robinhood' => ['stroke-violet-600 dark:stroke-violet-400', 'bg-violet-600 dark:bg-violet-400'],
        'other' => ['stroke-teal-600 dark:stroke-teal-400', 'bg-teal-600 dark:bg-teal-400'],
    ];
    $series = collect($chart['brokers'])->map(fn (array $broker, string $key) => [...$broker, 'colors' => $colors[$key] ?? $colors['other'], 'dashed' => false])->values()->all();

    if (count($series) > 1) {
        $series[] = ['label' => 'All brokers', 'values' => $chart['total'], 'colors' => ['stroke-zinc-500 dark:stroke-zinc-400', 'bg-zinc-500 dark:bg-zinc-400'], 'dashed' => true];
    }

    $scale = App\Support\ChartScale::for(array_merge(...array_map(fn (array $line) => array_map(fn (App\Support\Money $value) => $value->cents, $line['values']), $series)), $top, $bottom);
    $step = ($width - $left - $right) / max(count($dates) - 1, 1);
    $labelEvery = (int) ceil(count($dates) / 13);
    $label = fn (int $index) => $index === 0 ? $dates[0]->format('M j') : $dates[$index]->format('M');
    $x = fn (int $index) => $left + $step * $index;
@endphp

<div class="flex flex-col gap-3">
    <div class="flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-zinc-600 dark:text-zinc-400" aria-hidden="true">
        @foreach ($series as $line)
            <span class="flex items-center gap-1.5" wire:key="cash-legend-{{ $line['label'] }}">
                <span @class(['h-0.5 w-4', $line['colors'][1]])></span>{{ $line['label'] }}{{ $line['dashed'] ? ' (dashed)' : '' }}
            </span>
        @endforeach
        @if ($scale->min < 0)
            <span class="flex items-center gap-1.5"><span class="size-3 rounded-sm bg-red-500/15 ring-1 ring-red-500/40"></span>Below zero: negative cash</span>
        @endif
    </div>

    <div class="overflow-x-auto">
        <svg viewBox="0 0 {{ $width }} {{ $height }}" class="w-full min-w-[560px] text-[11px]" role="img" aria-label="Cash balance by broker over time">
            @if ($scale->min < 0)
                <rect x="{{ $left }}" y="{{ $scale->y(0) }}" width="{{ $width - $left - $right }}" height="{{ $bottom - $scale->y(0) }}" class="fill-red-500/10" />
            @endif

            @foreach ($scale->ticks as $tick)
                <line x1="{{ $left }}" x2="{{ $width - $right }}" y1="{{ $scale->y($tick) }}" y2="{{ $scale->y($tick) }}" @class(['stroke-zinc-400 dark:stroke-zinc-500' => $tick === 0, 'stroke-zinc-200 dark:stroke-zinc-800' => $tick !== 0]) stroke-width="1" />
                <text x="{{ $left - 8 }}" y="{{ $scale->y($tick) + 4 }}" text-anchor="end" class="fill-zinc-500 dark:fill-zinc-400">{{ App\Support\ChartScale::dollars($tick) }}</text>
            @endforeach

            @foreach ($dates as $index => $date)
                @if ($index % $labelEvery === 0 || $index === count($dates) - 1)
                    <text x="{{ $x($index) }}" y="{{ $bottom + 16 }}" text-anchor="{{ $index === 0 ? 'start' : ($index === count($dates) - 1 ? 'end' : 'middle') }}" class="fill-zinc-600 dark:fill-zinc-400">{{ $label($index) }}</text>
                    @if ($index === 0 || $date->month === 1)
                        <text x="{{ $x($index) }}" y="{{ $bottom + 30 }}" text-anchor="{{ $index === 0 ? 'start' : 'middle' }}" class="fill-zinc-400 dark:fill-zinc-500">{{ $date->format('Y') }}</text>
                    @endif
                @endif
            @endforeach

            @foreach ($series as $line)
                <polyline
                    points="{{ collect($line['values'])->map(fn (App\Support\Money $value, int $index) => round($x($index), 1).','.round($scale->y($value->cents), 1))->implode(' ') }}"
                    fill="none"
                    stroke-width="2"
                    stroke-linejoin="round"
                    @if ($line['dashed']) stroke-dasharray="5 4" @endif
                    class="{{ $line['colors'][0] }}"
                />
                @foreach ($line['values'] as $index => $value)
                    <circle cx="{{ $x($index) }}" cy="{{ $scale->y($value->cents) }}" r="3.5" stroke-width="2"
                        @class([$line['colors'][0], 'fill-white dark:fill-zinc-900' => ! $value->isNegative(), 'fill-red-600' => $value->isNegative()])>
                        <title>{{ $line['label'] }}, end of {{ $dates[$index]->format('M j, Y') }}{{ $index === 0 ? ' (start of period)' : '' }}: {{ $value->format() }}{{ $value->isNegative() ? ' (negative cash balance)' : '' }}</title>
                    </circle>
                @endforeach
            @endforeach
        </svg>
    </div>

    <details class="text-sm">
        <summary class="link w-fit text-xs">Show the numbers</summary>
        <div class="mt-2 overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        @foreach ($series as $line)
                            <th class="text-right" wire:key="cash-head-{{ $line['label'] }}">{{ $line['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($dates as $index => $date)
                        <tr wire:key="cash-row-{{ $index }}">
                            <td>{{ $date->format('M j, Y') }}{{ $index === 0 ? ' (start of period)' : '' }}</td>
                            @foreach ($series as $line)
                                <td class="num" wire:key="cash-cell-{{ $index }}-{{ $line['label'] }}"><x-money :amount="$line['values'][$index]" tone="balance" /></td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
</div>
