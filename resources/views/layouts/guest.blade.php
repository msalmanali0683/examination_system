<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 px-4 bg-gradient-to-b from-slate-900 to-slate-800">
            <div class="flex items-center gap-3 mt-6 sm:mt-0">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-indigo-500/20 text-indigo-300">
                    <x-application-logo class="h-7 w-7 fill-current" />
                </span>
                <span class="text-white">
                    <span class="block font-semibold leading-tight">Exam Duties &amp; Seat Plan</span>
                    <span class="block text-xs text-slate-400">Software Engineering</span>
                </span>
            </div>

            <div class="w-full sm:max-w-md mt-6 px-6 py-6 bg-white dark:bg-gray-800 shadow-xl overflow-hidden rounded-xl ring-1 ring-black/5">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
