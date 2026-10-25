{{--
    Contracts still open for each expiration date, soonest first, split into sold puts, sold calls and bought
    options. Dates within 7 days are marked. Each bar opens the Options list for that expiration month.
    Expects $rows from App\Support\DashboardCharts::openContractsByExpiration and $linkParams.
--}}
@props(['rows', 'linkParams' => [], 'limit' => 12])

@php
    $shown = array_slice($rows, 0, $limit);
    $width = 720;
    $height = 260;
    $left = 48;
    $right = 16;
    $top = 16;
    $bottom = $height - 44;
    $segments = [
        'shortPuts' => ['label' => 'Sold puts', 'fill' => 'fill-blue-600 dark:fill-blue-500', 'swatch' => 'bg-blue-600 dark:bg-blue-500'],
        'shortCalls' => ['label' => 'Sold calls', 'fill' => 'fill-sky-400 dark:fill-sky-300', 'swatch' => 'bg-sky-400 dark:bg-sky-300'],
        'long' => ['label' => 'Bought (long)', 'fill' => 'fill-violet-500 dark:fill-violet-400', 'swatch' => 'bg-violet-500 dark:bg-violet-400'],
    ];
    $scale = App\Support\ChartScale::for(array_map(fn (array $row) => $row['shortPuts'] + $row['shortCalls'] + $row['long'], $shown), $top, $bottom);
    $slot = ($width - $left - $right) / max(count($shown), 1);
    $barWidth = min(40, $slot * 0.6);
    $soon = App\Support\DataQualityAlerts::EXPIRING_SOON_DAYS;
@endphp

<div class="flex flex-col gap-3">
    <div class="flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-zinc-600 dark:text-zinc-400" aria-hidden="true">
        @foreach ($segments as $segment)
            <span class="flex items-center gap-1.5"><span @class(['size-3 rounded-sm', $segment['swatch']])></span>{{ $segment['label'] }}</span>
        @endforeach
        <span class="flex items-center gap-1.5"><span class="font-semibold text-red-700 dark:text-red-400">!</span>Expires within {{ $soon }} days</span>
    </div>

    <div class="overflow-x-auto">
        <svg viewBox="0 0 {{ $width }} {{ $height }}" class="w-full min-w-[560px] text-[11px]" role="img" aria-label="Open option contracts by expiration date">
            @foreach ($scale->ticks as $tick)
                <line x1="{{ $left }}" x2="{{ $width - $right }}" y1="{{ $scale->y($tick) }}" y2="{{ $scale->y($tick) }}" @class(['stroke-zinc-400 dark:stroke-zinc-500' => $tick === 0, 'stroke-zinc-200 dark:stroke-zinc-800' => $tick !== 0]) stroke-width="1" />
                <text x="{{ $left - 8 }}" y="{{ $scale->y($tick) + 4 }}" text-anchor="end" class="fill-zinc-500 dark:fill-zinc-400">{{ $tick }}</text>
            @endforeach

            @foreach ($shown as $index => $row)
                @php
                    $center = $left + $slot * ($index + 0.5);
                    $stacked = 0;
                    $total = $row['shortPuts'] + $row['shortCalls'] + $row['long'];
                    $dueSoon = $row['daysToExpiry'] <= $soon;
                    $days = match (true) {
                        $row['daysToExpiry'] < 0 => 'past expiration',
                        $row['daysToExpiry'] === 0 => 'today',
                        default => $row['daysToExpiry'].' '.Str::plural('day', $row['daysToExpiry']).' left',
                    };
                @endphp
                <a href="{{ route('options.index', [...$linkParams, 'status' => 'open', 'expiry' => $row['date']->format('Y-m')]) }}" wire:navigate>
                    <title>Expires {{ $row['date']->format('M j, Y') }} ({{ $days }}): {{ $total }} open {{ Str::plural('contract', $total) }}: {{ $row['shortPuts'] }} sold puts, {{ $row['shortCalls'] }} sold calls, {{ $row['long'] }} bought.</title>
                    <rect x="{{ $center - $slot / 2 }}" y="{{ $top }}" width="{{ $slot }}" height="{{ $bottom - $top }}" class="fill-transparent hover:fill-zinc-100 dark:hover:fill-zinc-800/60" />
                    @foreach ($segments as $key => $segment)
                        @if ($row[$key] > 0)
                            <rect x="{{ $center - $barWidth / 2 }}" y="{{ $scale->y($stacked + $row[$key]) }}" width="{{ $barWidth }}" height="{{ $scale->y($stacked) - $scale->y($stacked + $row[$key]) }}" class="{{ $segment['fill'] }}" />
                            @php($stacked += $row[$key])
                        @endif
                    @endforeach
                </a>
                <text x="{{ $center }}" y="{{ $bottom + 16 }}" text-anchor="middle" @class(['fill-red-700 font-semibold dark:fill-red-400' => $dueSoon, 'fill-zinc-600 dark:fill-zinc-400' => ! $dueSoon])>{{ $dueSoon ? '! ' : '' }}{{ $row['date']->format('M j') }}</text>
                <text x="{{ $center }}" y="{{ $bottom + 30 }}" text-anchor="middle" class="fill-zinc-400 dark:fill-zinc-500">{{ $row['daysToExpiry'] < 0 ? 'past' : ($row['daysToExpiry'] === 0 ? 'today' : $row['daysToExpiry'].'d') }}</text>
            @endforeach
        </svg>
    </div>

    @if (count($rows) > count($shown))
        <p class="form-help">Showing the next {{ count($shown) }} of {{ count($rows) }} expiration dates. The table lists them all.</p>
    @endif

    <details class="text-sm">
        <summary class="link w-fit text-xs">Show the numbers</summary>
        <div class="mt-2 overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Expiration</th>
                        <th class="text-right">Days left</th>
                        <th class="text-right">Sold puts</th>
                        <th class="text-right">Sold calls</th>
                        <th class="text-right">Bought</th>
                        <th class="text-right">Total open</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr wire:key="expiration-{{ $row['date']->format('Y-m-d') }}">
                            <td>{{ $row['date']->format('M j, Y') }}</td>
                            <td class="num">{{ $row['daysToExpiry'] < 0 ? 'Past' : $row['daysToExpiry'] }}</td>
                            <td class="num">{{ $row['shortPuts'] }}</td>
                            <td class="num">{{ $row['shortCalls'] }}</td>
                            <td class="num">{{ $row['long'] }}</td>
                            <td class="num font-semibold">{{ $row['shortPuts'] + $row['shortCalls'] + $row['long'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
</div>
