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
    <body class="font-sans antialiased">
        <div x-data="{ sidebarOpen: false }" class="min-h-screen flex bg-gray-50 dark:bg-gray-950">

            <!-- Mobile overlay -->
            <div x-show="sidebarOpen"
                 x-transition:enter="transition-opacity ease-linear duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition-opacity ease-linear duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 @click="sidebarOpen = false" class="fixed inset-0 z-30 bg-gray-900/60 lg:hidden" style="display: none;"></div>

            <livewire:layout.navigation />

            <!-- Main column -->
            <div class="flex-1 flex flex-col min-w-0 lg:pl-72">
                <!-- Topbar -->
                <header class="sticky top-0 z-20 bg-white/90 dark:bg-gray-900/90 backdrop-blur border-b border-gray-200 dark:border-gray-800">
                    <div class="flex items-center gap-3 px-4 sm:px-6 lg:px-8 py-4">
                        <button @click="sidebarOpen = true" class="lg:hidden -ml-1 p-2 rounded-md text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800">
                            <x-icon name="menu" class="h-5 w-5" />
                        </button>

                        <div class="min-w-0 flex-1">
                            @if (isset($header))
                                {{ $header }}
                            @endif
                        </div>
                    </div>
                </header>

                <!-- Page Content -->
                <main class="flex-1 px-4 sm:px-6 lg:px-8 py-6 sm:py-8">
                    <div class="max-w-6xl mx-auto space-y-6">
                        {{ $slot }}
                    </div>
                </main>

                <footer class="px-4 sm:px-6 lg:px-8 py-4 text-center text-xs text-gray-400 dark:text-gray-600">
                    Developed by Asia Maqsood
                </footer>
            </div>
        </div>
    </body>
</html>
