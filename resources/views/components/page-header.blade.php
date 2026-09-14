@props(['title', 'subtitle' => null, 'icon' => null, 'back' => null])

<div class="flex items-center justify-between gap-4 flex-wrap">
    <div class="flex items-center gap-3">
        @if ($icon)
            <span class="hidden sm:flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-300">
                <x-icon :name="$icon" class="h-5 w-5" />
            </span>
        @endif
        <div>
            @if ($back)
                <a href="{{ $back }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-medium text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 mb-0.5">
                    <x-icon name="arrow-left" class="h-3 w-3" /> Back
                </a>
            @endif
            <h2 class="font-semibold text-lg sm:text-xl text-gray-800 dark:text-gray-100 leading-tight">
                {{ $title }}
            </h2>
            @if ($subtitle)
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ $subtitle }}</p>
            @endif
        </div>
    </div>

    @isset($actions)
        <div class="flex items-center gap-2 flex-wrap">
            {{ $actions }}
        </div>
    @endisset
</div>
