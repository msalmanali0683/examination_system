@props(['label', 'value', 'icon' => null, 'color' => 'primary'])

@php
$colors = [
    'primary' => 'bg-primary-50 dark:bg-primary-900/40 text-primary-600 dark:text-primary-300',
    'green' => 'bg-green-50 dark:bg-green-900/40 text-green-600 dark:text-green-300',
];

// Only a green (success) stat keeps its own hue; every other colour name a
// caller passes (indigo, blue, amber, rose ...) is just the theme's accent.
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center gap-3 bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-200 dark:ring-gray-700/60 p-4']) }}>
    @if ($icon)
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg {{ $colors[$color] ?? $colors['primary'] }}">
            <x-icon :name="$icon" class="h-5 w-5" />
        </span>
    @endif
    <div class="min-w-0">
        <div class="text-xl font-semibold text-gray-900 dark:text-gray-100 leading-none">{{ $value }}</div>
        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 truncate">{{ $label }}</div>
    </div>
</div>
