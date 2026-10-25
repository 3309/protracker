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
    <body class="flex min-h-screen items-center justify-center bg-zinc-50 p-4 font-sans text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <div class="fixed top-4 right-4">
            @include('partials.theme-switcher')
        </div>

        <main class="w-full max-w-sm">
            {{ $slot }}
        </main>
    </body>
</html>
