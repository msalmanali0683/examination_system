@props(['status'])

<div class="flex items-center gap-2 mt-2 flex-wrap">
    @if ($status['state'] === 'in_progress')
        <span class="text-xs text-indigo-500 dark:text-indigo-400 inline-flex items-center gap-1.5">
            <svg class="animate-spin h-3 w-3" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            Generating&hellip; this can take a minute or two for a large session.
        </span>
    @elseif ($status['state'] === 'failed')
        <span class="text-xs text-red-500 dark:text-red-400" title="{{ $status['error'] }}">Generation failed.</span>
        <button type="button" wire:click="regenerate" wire:loading.attr="disabled" wire:target="regenerate" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline disabled:opacity-50">
            Retry
        </button>
    @else
        @if ($status['generatedAt'])
            <span class="text-xs text-gray-400" title="{{ $status['generatedAt']->format('d M Y H:i') }}">Generated {{ $status['generatedAt']->diffForHumans() }}</span>
        @else
            <span class="text-xs text-gray-400 italic">Not generated yet &mdash; first download will build it</span>
        @endif
        <button type="button" wire:click="regenerate" wire:loading.attr="disabled" wire:target="regenerate" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline disabled:opacity-50">
            <span wire:loading.remove wire:target="regenerate">Regenerate</span>
            <span wire:loading wire:target="regenerate">Regenerating&hellip;</span>
        </button>
    @endif
</div>
