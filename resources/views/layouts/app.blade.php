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
        {{-- Top bar: the sidebar button, the app name, the most used actions, the theme and logging out. --}}
        <header class="sticky top-0 z-40 h-(--topbar-height) border-b border-zinc-200 bg-white/90 backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/90">
            <div class="flex h-full items-center gap-2 px-3 sm:px-4">
                <button
                    type="button"
                    data-sidebar-toggle
                    onclick="window.sidebar.toggle()"
                    aria-controls="sidebar"
                    aria-expanded="true"
                    aria-label="Collapse sidebar"
                    class="flex rounded-md p-2 text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-white"
                >
                    <x-icon name="menu" />
                </button>

                <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-2 text-base font-bold tracking-tight" wire:navigate>
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-md bg-blue-600 text-white">
                        <x-icon name="portfolio" class="size-4" />
                    </span>
                    <span class="truncate max-sm:sr-only">{{ config('app.name') }}</span>
                </a>

                <div class="ms-auto flex items-center gap-2">
                    <a href="{{ route('stock-trades.create') }}" title="Add a stock trade" class="btn-secondary px-2 py-1.5 sm:px-3" wire:navigate>
                        <x-icon name="plus" class="size-4" />
                        <span class="max-sm:sr-only">Stock trade</span>
                    </a>
                    <a href="{{ route('options.create') }}" title="Sell an option" class="btn-secondary px-2 py-1.5 sm:px-3" wire:navigate>
                        <x-icon name="sell" class="size-4" />
                        <span class="max-sm:sr-only">Sell option</span>
                    </a>
                    <a href="{{ route('options.buy') }}" title="Buy an option" class="btn-secondary px-2 py-1.5 sm:px-3" wire:navigate>
                        <x-icon name="buy" class="size-4" />
                        <span class="max-sm:sr-only">Buy option</span>
                    </a>

                    <span class="mx-1 h-6 w-px bg-zinc-200 dark:bg-zinc-700" aria-hidden="true"></span>

                    @include('partials.theme-switcher')

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" title="Log out" class="flex items-center gap-2 rounded-md p-2 text-sm font-medium text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-white">
                            <x-icon name="logout" />
                            <span class="max-md:sr-only">Log out</span>
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <div class="lg:grid lg:grid-cols-[var(--sidebar-width)_minmax(0,1fr)] lg:transition-[grid-template-columns] lg:duration-200 motion-reduce:transition-none">
            {{--
                Sidebar: navigation only, and the one place in it that scrolls. A column below the top bar on wide
                screens that can shrink to an icon-only rail, and a menu that slides in from the side on phones and
                tablets. See partials.sidebar-script.
            --}}
            <aside
                id="sidebar"
                class="peer fixed start-0 top-(--topbar-height) bottom-0 z-30 w-72 max-w-[85vw] overflow-x-hidden overflow-y-auto overscroll-contain border-e border-zinc-200 bg-white px-3 py-4 transition-[translate,visibility] duration-200 motion-reduce:transition-none max-lg:invisible max-lg:-translate-x-full max-lg:data-open:visible max-lg:data-open:translate-x-0 max-lg:data-open:shadow-xl lg:sticky lg:h-[calc(100dvh-var(--topbar-height))] lg:w-auto lg:max-w-none rail:px-2 dark:border-zinc-800 dark:bg-zinc-900"
            >
                @include('partials.navigation')
            </aside>
            <script>window.sidebar.restore();</script>

            {{-- Shown behind the open menu on phones and tablets; clicking it closes the menu. --}}
            <div onclick="window.sidebar.closeMenu()" class="fixed inset-x-0 top-(--topbar-height) bottom-0 z-20 hidden bg-zinc-950/40 max-lg:peer-data-open:block" aria-hidden="true"></div>

            <main class="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-6 px-4 py-6 sm:px-6 sm:py-8">
                @if (session('message'))
                    <x-alert level="success">{{ session('message') }}</x-alert>
                @endif

                {{ $slot }}
            </main>
        </div>
    </body>
</html>
