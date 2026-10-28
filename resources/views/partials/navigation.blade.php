{{--
    The grouped sidebar menu. Every link is an existing page; "active" lists the routes that highlight it.
    Each group opens and closes on its own (see partials.sidebar-script); the group holding the current page is
    marked data-current so it always opens, whatever was remembered.
--}}
@php
    $optionsStatus = request()->routeIs('options.index') ? request()->query('status', 'open') : null;

    $groups = [
        ['key' => 'overview', 'label' => 'Overview', 'links' => [
            ['label' => 'Dashboard', 'icon' => 'home', 'url' => route('dashboard'), 'active' => request()->routeIs('dashboard')],
            ['label' => 'AI Trade Assistant', 'icon' => 'sparkles', 'url' => route('trade-assistant'), 'active' => request()->routeIs('trade-assistant')],
        ]],
        ['key' => 'stocks', 'label' => 'Stocks', 'links' => [
            ['label' => 'Positions', 'icon' => 'portfolio', 'url' => route('positions'), 'active' => request()->routeIs('positions')],
            ['label' => 'Stock trades', 'icon' => 'list', 'url' => route('stock-trades.index'), 'active' => request()->routeIs('stock-trades.*')],
            ['label' => 'Dividends', 'icon' => 'dollar', 'url' => route('dividends.index'), 'active' => request()->routeIs('dividends.*')],
            ['label' => 'Prices', 'icon' => 'tag', 'url' => route('prices.index'), 'active' => request()->routeIs('prices.*')],
        ]],
        ['key' => 'options', 'label' => 'Options', 'links' => [
            ['label' => 'Open options', 'icon' => 'options', 'url' => route('options.index'), 'active' => $optionsStatus === 'open' || request()->routeIs('options.show', 'options.edit', 'options.chain', 'options.events.*')],
            ['label' => 'Closed options', 'icon' => 'archive', 'url' => route('options.index', ['status' => 'closed']), 'active' => $optionsStatus === 'closed'],
            ['label' => 'All options', 'icon' => 'stack', 'url' => route('options.index', ['status' => 'all']), 'active' => $optionsStatus === 'all'],
            ['label' => 'Sell option', 'icon' => 'sell', 'url' => route('options.create'), 'active' => request()->routeIs('options.create')],
            ['label' => 'Buy option', 'icon' => 'buy', 'url' => route('options.buy'), 'active' => request()->routeIs('options.buy')],
        ]],
        ['key' => 'cash', 'label' => 'Cash', 'links' => [
            ['label' => 'Cash ledger', 'icon' => 'cash', 'url' => route('cash.index'), 'active' => request()->routeIs('cash.index', 'cash.movements.edit')],
            ['label' => 'Reconciliation', 'icon' => 'success', 'url' => route('cash.reconciliation'), 'active' => request()->routeIs('cash.reconciliation', 'cash.broker-balances.*')],
            ['label' => 'Add cash entry', 'icon' => 'plus', 'url' => route('cash.movements.create'), 'active' => request()->routeIs('cash.movements.create')],
        ]],
        ['key' => 'imports', 'label' => 'Imports & data', 'links' => [
            ['label' => 'Import CSV', 'icon' => 'import', 'url' => route('imports.create'), 'active' => request()->routeIs('imports.create')],
            ['label' => 'Import history', 'icon' => 'clock', 'url' => route('imports.index'), 'active' => request()->routeIs('imports.index', 'imports.show')],
            ['label' => 'Rows to review', 'icon' => 'review', 'url' => route('imports.review'), 'active' => request()->routeIs('imports.review')],
        ]],
        ['key' => 'reports', 'label' => 'Reports', 'links' => [
            ['label' => 'Realized P/L', 'icon' => 'trend', 'url' => route('realized.index'), 'active' => request()->routeIs('realized.*')],
        ]],
        ['key' => 'settings', 'label' => 'Settings', 'links' => [
            ['label' => 'Accounts', 'icon' => 'bank', 'url' => route('accounts.index'), 'active' => request()->routeIs('accounts.*')],
            ['label' => 'Opening balances', 'icon' => 'flag', 'url' => route('opening-balances.index'), 'active' => request()->routeIs('opening-balances.*')],
            ['label' => 'Settings', 'icon' => 'settings', 'url' => route('settings'), 'active' => request()->routeIs('settings')],
            ['label' => 'Password', 'icon' => 'lock', 'url' => route('settings.password'), 'active' => request()->routeIs('settings.password')],
        ]],
    ];
@endphp

<nav aria-label="Main" class="flex flex-col gap-3 text-sm rail:gap-0">
    @foreach ($groups as $group)
        <div
            data-nav-section="{{ $group['key'] }}"
            @if (collect($group['links'])->contains('active', true)) data-current @endif
            class="rail:border-t rail:border-zinc-200 rail:py-2 rail:first:border-0 rail:first:pt-0 dark:rail:border-zinc-800"
        >
            <button
                type="button"
                aria-expanded="true"
                aria-controls="nav-section-{{ $group['key'] }}"
                onclick="window.sidebar.toggleSection(this)"
                class="group/heading flex w-full items-center justify-between rounded-md px-3 py-1.5 text-xs font-semibold tracking-wide text-zinc-500 uppercase hover:bg-zinc-100 hover:text-zinc-800 rail:hidden dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
            >
                {{ $group['label'] }}
                <x-icon name="chevron-down" class="size-4 transition-transform duration-150 group-aria-[expanded=false]/heading:-rotate-90 motion-reduce:transition-none" />
            </button>

            <div id="nav-section-{{ $group['key'] }}" class="nav-panel">
                <ul class="flex flex-col gap-0.5 pt-0.5 rail:pt-0">
                    @foreach ($group['links'] as $link)
                        <li>
                            <a
                                href="{{ $link['url'] }}"
                                wire:navigate
                                data-tooltip="{{ $link['label'] }}"
                                @if ($link['active']) aria-current="page" @endif
                                @class([
                                    'nav-link group/link flex items-center gap-2.5 rounded-md px-3 py-1.5 rail:justify-center rail:px-0 rail:py-2',
                                    'bg-blue-50 font-semibold text-blue-700 dark:bg-blue-950/60 dark:text-blue-300' => $link['active'],
                                    'text-zinc-700 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-white' => ! $link['active'],
                                ])
                            >
                                <x-icon
                                    :name="$link['icon']"
                                    @class([
                                        'size-4 rail:size-5',
                                        'text-blue-600 dark:text-blue-400' => $link['active'],
                                        'text-zinc-400 group-hover/link:text-zinc-600 dark:text-zinc-500 dark:group-hover/link:text-zinc-300' => ! $link['active'],
                                    ])
                                />
                                <span class="truncate rail:sr-only">{{ $link['label'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endforeach
</nav>
