@props(['icon' => 'document', 'title', 'description' => null])

<div class="text-center py-12 px-4">
    <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700 text-gray-400 dark:text-gray-500">
        <x-icon :name="$icon" class="h-6 w-6" />
    </span>
    <h3 class="mt-3 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $title }}</h3>
    @if ($description)
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
    @endif
    @isset($actions)
        <div class="mt-4 flex justify-center gap-2">{{ $actions }}</div>
    @endisset
</div>
