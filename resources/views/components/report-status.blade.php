@props(['type', 'generatedAt'])

<div class="flex items-center gap-2 mt-2">
    @if ($generatedAt)
        <span class="text-xs text-gray-400" title="{{ $generatedAt->format('d M Y H:i') }}">Generated {{ $generatedAt->diffForHumans() }}</span>
    @else
        <span class="text-xs text-gray-400 italic">Not generated yet &mdash; first download will build it</span>
    @endif
    <button type="button" wire:click="regenerate('{{ $type }}')" wire:loading.attr="disabled" wire:target="regenerate('{{ $type }}')" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline disabled:opacity-50">
        <span wire:loading.remove wire:target="regenerate('{{ $type }}')">Regenerate</span>
        <span wire:loading wire:target="regenerate('{{ $type }}')">Regenerating&hellip;</span>
    </button>
</div>
