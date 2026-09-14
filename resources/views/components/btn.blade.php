@props(['variant' => 'primary', 'size' => 'md', 'href' => null, 'icon' => null])

@php
$base = 'inline-flex items-center justify-center gap-1.5 font-medium rounded-lg transition duration-150 ease-in-out focus:outline-none focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-gray-900 disabled:opacity-50 disabled:cursor-not-allowed';

$sizes = [
    'sm' => 'px-2.5 py-1.5 text-xs',
    'md' => 'px-4 py-2 text-sm',
    'lg' => 'px-5 py-2.5 text-sm',
];

$variants = [
    'primary' => 'bg-indigo-600 text-white shadow-sm hover:bg-indigo-500 focus:ring-indigo-500',
    'secondary' => 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 ring-1 ring-gray-300 dark:ring-gray-600 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-600 focus:ring-indigo-500',
    'dark' => 'bg-gray-900 dark:bg-gray-700 text-white shadow-sm hover:bg-gray-700 dark:hover:bg-gray-600 focus:ring-gray-500',
    'danger' => 'bg-white dark:bg-gray-700 text-red-600 dark:text-red-400 ring-1 ring-red-200 dark:ring-red-900 shadow-sm hover:bg-red-50 dark:hover:bg-red-900/30 focus:ring-red-500',
    'danger-solid' => 'bg-red-600 text-white shadow-sm hover:bg-red-500 focus:ring-red-500',
    'ghost' => 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700/60 focus:ring-indigo-500',
];

$classes = $base . ' ' . ($sizes[$size] ?? $sizes['md']) . ' ' . ($variants[$variant] ?? $variants['primary']);
$tag = $href ? 'a' : 'button';
@endphp

<{{ $tag }} @if($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => $classes]) }}>
    @if ($icon)
        <x-icon :name="$icon" class="h-4 w-4 shrink-0" />
    @endif
    {{ $slot }}
</{{ $tag }}>
