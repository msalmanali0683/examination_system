@props(['color' => 'gray'])

@php
$colors = [
    'gray' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
    'blue' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    'green' => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    'yellow' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
    'red' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    'indigo' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300',
];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 text-xs font-medium px-2 py-1 rounded-full ' . ($colors[$color] ?? $colors['gray'])]) }}>
    {{ $slot }}
</span>
