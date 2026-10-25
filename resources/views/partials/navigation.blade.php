{{-- The grouped sidebar menu. Every link is an existing page; "active" lists the routes that highlight it. --}}
@php
    $optionsStatus = request()->routeIs('options.index') ? request()->query('status', 'open') : null;

    $groups = [
        ['label' => null, 'icon' => 'home', 'links' => [
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'active' => request()->routeIs('dashboard')],
        ]],
        ['label' => 'Portfolio', 'icon' => 'portfolio', 'links' => [
            ['label' => 'Positions', 'url' => route('positions'), 'active' => request()->routeIs('positions')],
            ['label' => 'Stock trades', 'url' => route('stock-trades.index'), 'active' => request()->routeIs('stock-trades.*')],
            ['label' => 'Dividends', 'url' => route('dividends.index'), 'active' => request()->routeIs('dividends.*')],
            ['label' => 'Prices', 'url' => route('prices.index'), 'active' => request()->routeIs('prices.*')],
        ]],
        ['label' => 'Options', 'icon' => 'options', 'links' => [
            ['label' => 'Open options', 'url' => route('options.index'), 'active' => $optionsStatus === 'open' || request()->routeIs('options.show', 'options.edit', 'options.chain', 'options.events.*')],
            ['label' => 'Closed options', 'url' => route('options.index', ['status' => 'closed']), 'active' => $optionsStatus === 'closed'],
            ['label' => 'All options', 'url' => route('options.index', ['status' => 'all']), 'active' => $optionsStatus === 'all'],
            ['label' => 'Sell option', 'url' => route('options.create'), 'active' => request()->routeIs('options.create')],
            ['label' => 'Buy option', 'url' => route('options.buy'), 'active' => request()->routeIs('options.buy')],
        ]],
        ['label' => 'Cash', 'icon' => 'cash', 'links' => [
            ['label' => 'Cash ledger', 'url' => route('cash.index'), 'active' => request()->routeIs('cash.index', 'cash.movements.edit')],
            ['label' => 'Reconciliation', 'url' => route('cash.reconciliation'), 'active' => request()->routeIs('cash.reconciliation', 'cash.broker-balances.*')],
            ['label' => 'Add cash entry', 'url' => route('cash.movements.create'), 'active' => request()->routeIs('cash.movements.create')],
        ]],
        ['label' => 'Import', 'icon' => 'import', 'links' => [
            ['label' => 'Import CSV', 'url' => route('imports.create'), 'active' => request()->routeIs('imports.create')],
            ['label' => 'Import history', 'url' => route('imports.index'), 'active' => request()->routeIs('imports.index', 'imports.show')],
            ['label' => 'Rows to review', 'url' => route('imports.review'), 'active' => request()->routeIs('imports.review')],
        ]],
        ['label' => 'Reports', 'icon' => 'report', 'links' => [
            ['label' => 'Realized P/L', 'url' => route('realized.index'), 'active' => request()->routeIs('realized.*')],
        ]],
        ['label' => 'Setup', 'icon' => 'settings', 'links' => [
            ['label' => 'Accounts', 'url' => route('accounts.index'), 'active' => request()->routeIs('accounts.*')],
            ['label' => 'Opening balances', 'url' => route('opening-balances.index'), 'active' => request()->routeIs('opening-balances.*')],
            ['label' => 'Settings', 'url' => route('settings'), 'active' => request()->routeIs('settings')],
            ['label' => 'Password', 'url' => route('settings.password'), 'active' => request()->routeIs('settings.password')],
        ]],
    ];
@endphp

<nav aria-label="Main" class="flex flex-col gap-5 text-sm">
    @foreach ($groups as $group)
        <div class="flex flex-col gap-0.5">
            @if ($group['label'])
                <div class="flex items-center gap-2 px-3 pb-1 text-xs font-semibold tracking-wide text-zinc-500 uppercase dark:text-zinc-400">
                    <x-icon :name="$group['icon']" class="size-4" />
                    {{ $group['label'] }}
                </div>
            @endif
            @foreach ($group['links'] as $link)
                <a
                    href="{{ $link['url'] }}"
                    wire:navigate
                    @if ($link['active']) aria-current="page" @endif
                    @class([
                        'flex items-center gap-2 rounded-md px-3 py-1.5',
                        'ps-9' => $group['label'] !== null,
                        'bg-blue-50 font-semibold text-blue-700 dark:bg-blue-950/60 dark:text-blue-300' => $link['active'],
                        'text-zinc-700 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-white' => ! $link['active'],
                    ])
                >
                    @if ($group['label'] === null)
                        <x-icon :name="$group['icon']" class="size-4" />
                    @endif
                    {{ $link['label'] }}
                </a>
            @endforeach
        </div>
    @endforeach
</nav>
