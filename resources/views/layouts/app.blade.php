<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ isset($title) ? $title.' - ' : '' }}{{ config('app.name') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">

        @include('partials.theme-script')
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-zinc-50 font-sans text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <div class="lg:grid lg:min-h-screen lg:grid-cols-[15rem_minmax(0,1fr)]">
            {{-- Sidebar: always shown on wide screens, opened with the menu button on phones and tablets. --}}
            <aside
                id="sidebar"
                class="fixed inset-0 z-40 hidden overflow-y-auto border-e border-zinc-200 bg-white px-3 py-4 lg:sticky lg:top-0 lg:block lg:h-screen dark:border-zinc-800 dark:bg-zinc-900"
            >
                <div class="flex items-center justify-between px-3 pb-5">
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-2 text-base font-bold tracking-tight" wire:navigate>
                        <span class="flex size-7 items-center justify-center rounded-md bg-blue-600 text-white">
                            <x-icon name="portfolio" class="size-4" />
                        </span>
                        {{ config('app.name') }}
                    </a>
                    <button type="button" onclick="document.getElementById('sidebar').classList.add('hidden')" class="rounded-md p-1 text-zinc-500 hover:bg-zinc-100 lg:hidden dark:hover:bg-zinc-800">
                        <x-icon name="close" />
                        <span class="sr-only">Close menu</span>
                    </button>
                </div>

                @include('partials.navigation')

                <div class="mt-6 flex flex-col gap-3 border-t border-zinc-200 px-3 pt-4 dark:border-zinc-800">
                    @include('partials.theme-switcher')
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="flex items-center gap-2 text-sm text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">
                            <x-icon name="logout" class="size-4" />
                            Log out
                        </button>
                    </form>
                </div>
            </aside>

            <div class="flex min-w-0 flex-col">
                {{-- Top bar: the menu button on small screens, and the most used actions everywhere. --}}
                <header class="sticky top-0 z-30 border-b border-zinc-200 bg-white/90 backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/90">
                    <div class="mx-auto flex max-w-7xl items-center gap-3 px-4 py-2.5 sm:px-6">
                        <button type="button" onclick="document.getElementById('sidebar').classList.remove('hidden')" class="-ms-1 rounded-md p-1 text-zinc-600 hover:bg-zinc-100 lg:hidden dark:text-zinc-300 dark:hover:bg-zinc-800">
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
