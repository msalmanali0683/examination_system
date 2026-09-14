@props(['padded' => true])

<div {{ $attributes->merge(['class' => 'bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-200 dark:ring-gray-700/60 ' . ($padded ? 'p-4 sm:p-6' : '')]) }}>
    {{ $slot }}
</div>
