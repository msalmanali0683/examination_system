@props(['model' => 'perPage'])

<div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
    <label for="per-page">Show</label>
    <select id="per-page" wire:model.live="{{ $model }}" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
        <option value="10">10</option>
        <option value="25">25</option>
        <option value="50">50</option>
        <option value="100">100</option>
    </select>
    <span>per page</span>
</div>
