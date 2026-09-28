<!DOCTYPE html>
@php
    $theme = \App\Support\Themes::themeFor(auth()->user());
    $appearance = \App\Support\Themes::appearanceFor(auth()->user());
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $theme }}" data-appearance="{{ $appearance }}" @guest data-guest @endguest @class(['dark' => $appearance === 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Theme: applied before first paint so there's no flash of the wrong colours -->
        @include('partials.theme-init')

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="fixed top-4 right-4 z-50" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
            <button type="button" @click="open = ! open" title="Theme &amp; appearance" aria-label="Theme and appearance"
                class="flex h-9 w-9 items-center justify-center rounded-lg bg-white/10 text-slate-200 hover:bg-white/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-400">
                <x-icon name="palette" class="h-5 w-5" />
            </button>
            <div x-show="open" x-transition style="display: none;"
                class="absolute right-0 mt-2 w-72 rounded-xl bg-white p-4 shadow-xl ring-1 ring-black/10 dark:bg-gray-800">
                <x-theme-picker />
            </div>
        </div>

        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 px-4 bg-gradient-to-b from-slate-900 to-slate-800">
            <div class="flex items-center gap-3 mt-6 sm:mt-0">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-primary-500/20 text-primary-300">
                    <x-application-logo class="h-7 w-7 fill-current" />
                </span>
                <span class="text-white">
                    <span class="block font-semibold leading-tight">Exam Duties &amp; Seat Plan</span>
                    <span class="block text-xs text-slate-400">Faculty of Information Technology</span>
                </span>
            </div>

            <div class="w-full sm:max-w-md mt-6 px-6 py-6 bg-white dark:bg-gray-800 shadow-xl overflow-hidden rounded-xl ring-1 ring-black/5">
                {{ $slot }}
            </div>

            <p class="mt-6 mb-6 text-xs text-slate-500">Developed by Asia Maqsood</p>
        </div>
    </body>
</html>
