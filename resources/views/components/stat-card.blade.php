@props(['label', 'value', 'icon' => null, 'color' => 'indigo'])

@php
$colors = [
    'indigo' => 'bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-300',
    'green' => 'bg-green-50 dark:bg-green-900/40 text-green-600 dark:text-green-300',
    'blue' => 'bg-blue-50 dark:bg-blue-900/40 text-blue-600 dark:text-blue-300',
    'amber' => 'bg-amber-50 dark:bg-amber-900/40 text-amber-600 dark:text-amber-300',
    'rose' => 'bg-rose-50 dark:bg-rose-900/40 text-rose-600 dark:text-rose-300',
];
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center gap-3 bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-200 dark:ring-gray-700/60 p-4']) }}>
    @if ($icon)
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg {{ $colors[$color] ?? $colors['indigo'] }}">
            <x-icon :name="$icon" class="h-5 w-5" />
        </span>
    @endif
    <div class="min-w-0">
        <div class="text-xl font-semibold text-gray-900 dark:text-gray-100 leading-none">{{ $value }}</div>
        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 truncate">{{ $label }}</div>
    </div>
</div>
