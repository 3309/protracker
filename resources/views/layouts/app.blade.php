<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ isset($title) ? $title.' - ' : '' }}{{ config('app.name') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-zinc-50 font-sans text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <header class="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <nav class="mx-auto flex max-w-5xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3 text-sm">
                <a href="{{ route('dashboard') }}" class="font-semibold">{{ config('app.name') }}</a>

                @php
                    $links = [
                        ['route' => 'dashboard', 'label' => 'Dashboard', 'active' => 'dashboard'],
                        ['route' => 'accounts.index', 'label' => 'Accounts', 'active' => 'accounts.*'],
                        ['route' => 'settings', 'label' => 'Settings', 'active' => 'settings'],
                        ['route' => 'settings.password', 'label' => 'Password', 'active' => 'settings.password'],
                    ];
                @endphp

                @foreach ($links as $link)
                    <a
                        href="{{ route($link['route']) }}"
                        @class([
                            'font-medium text-zinc-900 underline underline-offset-4 dark:text-white' => request()->routeIs($link['active']),
                            'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' => ! request()->routeIs($link['active']),
                        ])
                    >{{ $link['label'] }}</a>
                @endforeach

                <form method="POST" action="{{ route('logout') }}" class="ms-auto">
                    @csrf
                    <button type="submit" class="text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">Log out</button>
                </form>
            </nav>
        </header>

        <main class="mx-auto flex max-w-5xl flex-col gap-6 px-4 py-8">
            @if (session('message'))
                <div class="status-message">{{ session('message') }}</div>
            @endif

            {{ $slot }}
        </main>
    </body>
</html>
