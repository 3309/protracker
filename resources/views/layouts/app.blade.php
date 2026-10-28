<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ isset($title) ? $title.' - ' : '' }}{{ config('app.name') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">

        @include('partials.theme-script')
        @include('partials.sidebar-script')
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-zinc-50 font-sans text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <div class="lg:grid lg:min-h-screen lg:grid-cols-[var(--sidebar-width)_minmax(0,1fr)] lg:transition-[grid-template-columns] lg:duration-200 motion-reduce:transition-none">
            {{--
                Sidebar: a column on wide screens that can shrink to an icon-only rail, and a menu that slides in
                from the side on phones and tablets. See partials.sidebar-script.
            --}}
            <aside
                id="sidebar"
                class="peer fixed inset-y-0 start-0 z-40 flex w-72 max-w-[85vw] flex-col overflow-x-hidden border-e border-zinc-200 bg-white transition-[translate,visibility] duration-200 motion-reduce:transition-none max-lg:invisible max-lg:-translate-x-full max-lg:data-open:visible max-lg:data-open:translate-x-0 max-lg:data-open:shadow-xl lg:sticky lg:top-0 lg:h-screen lg:w-auto lg:max-w-none dark:border-zinc-800 dark:bg-zinc-900"
            >
                <div class="flex items-center justify-between gap-2 px-3 py-4 rail:flex-col rail:gap-3 rail:px-0">
                    <a href="{{ route('dashboard') }}" data-tooltip="{{ config('app.name') }}" class="flex min-w-0 items-center gap-2 px-3 text-base font-bold tracking-tight rail:px-0" wire:navigate>
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-md bg-blue-600 text-white">
                            <x-icon name="portfolio" class="size-4" />
                        </span>
                        <span class="truncate rail:sr-only">{{ config('app.name') }}</span>
                    </a>
                    <button
                        type="button"
                        data-sidebar-toggle
                        onclick="window.sidebar.toggleCollapsed()"
                        aria-controls="sidebar"
                        aria-expanded="true"
                        aria-label="Collapse sidebar"
                        class="hidden rounded-md p-1.5 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 lg:flex dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white"
                    >
                        <x-icon name="collapse" class="size-4 transition-transform duration-200 rail:rotate-180 motion-reduce:transition-none" />
                    </button>
                    <button type="button" data-sidebar-close onclick="window.sidebar.closeMenu()" class="rounded-md p-1 text-zinc-500 hover:bg-zinc-100 lg:hidden dark:hover:bg-zinc-800">
                        <x-icon name="close" />
                        <span class="sr-only">Close menu</span>
                    </button>
                </div>

                <div class="min-h-0 flex-1 overflow-x-hidden overflow-y-auto px-3 pb-4 rail:px-2">
                    @include('partials.navigation')
                </div>

                <div class="flex flex-col gap-3 border-t border-zinc-200 px-6 py-4 rail:items-center rail:px-2 dark:border-zinc-800">
                    <div class="rail:hidden">
                        @include('partials.theme-switcher')
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" data-tooltip="Log out" class="flex items-center gap-2 rounded-md text-sm text-zinc-600 hover:text-zinc-900 rail:p-2 rail:hover:bg-zinc-100 dark:text-zinc-400 dark:hover:text-white dark:rail:hover:bg-zinc-800">
                            <x-icon name="logout" class="size-4 rail:size-5" />
                            <span class="rail:sr-only">Log out</span>
                        </button>
                    </form>
                </div>
            </aside>
            <script>window.sidebar.restore();</script>

            {{-- Shown behind the open menu on phones and tablets; clicking it closes the menu. --}}
            <div onclick="window.sidebar.closeMenu()" class="fixed inset-0 z-30 hidden bg-zinc-950/40 max-lg:peer-data-open:block" aria-hidden="true"></div>

            <div class="flex min-w-0 flex-col">
                {{-- Top bar: the menu button on small screens, and the most used actions everywhere. --}}
                <header class="sticky top-0 z-20 border-b border-zinc-200 bg-white/90 backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/90">
                    <div class="mx-auto flex max-w-7xl items-center gap-3 px-4 py-2.5 sm:px-6">
                        <button type="button" data-sidebar-open onclick="window.sidebar.openMenu()" aria-controls="sidebar" class="-ms-1 rounded-md p-1 text-zinc-600 hover:bg-zinc-100 lg:hidden dark:text-zinc-300 dark:hover:bg-zinc-800">
                            <x-icon name="menu" class="size-6" />
                            <span class="sr-only">Open menu</span>
                        </button>
                        <a href="{{ route('dashboard') }}" class="font-bold tracking-tight lg:hidden" wire:navigate>{{ config('app.name') }}</a>

                        <div class="ms-auto flex flex-wrap items-center justify-end gap-2">
                            <a href="{{ route('stock-trades.create') }}" class="btn-secondary px-3 py-1.5" wire:navigate>
                                <x-icon name="plus" class="size-4" />
                                <span>Stock trade</span>
                            </a>
                            <a href="{{ route('options.create') }}" class="btn-secondary px-3 py-1.5" wire:navigate>
                                <x-icon name="plus" class="size-4" />
                                <span>Sell option</span>
                            </a>
                            <a href="{{ route('options.buy') }}" class="btn-secondary px-3 py-1.5" wire:navigate>
                                <x-icon name="plus" class="size-4" />
                                <span>Buy option</span>
                            </a>
                        </div>
                    </div>
                </header>

                <main class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 sm:py-8">
                    @if (session('message'))
                        <x-alert level="success">{{ session('message') }}</x-alert>
                    @endif

                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
