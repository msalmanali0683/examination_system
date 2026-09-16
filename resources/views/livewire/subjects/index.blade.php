<x-slot name="header">
    <x-page-header title="Subjects" subtitle="Course catalog used across every exam session." icon="document" />
</x-slot>

<div class="space-y-6">
@if (session('status'))
    <div class="p-4 bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-300 rounded-lg text-sm">
        {{ session('status') }}
    </div>
@endif

@if (session('error'))
    <div class="p-4 bg-yellow-50 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-300 rounded-lg text-sm">
        {{ session('error') }}
    </div>
@endif

<x-modal name="merge-subjects" :show="$showMergeModal" focusable max-width="lg">
    <div class="p-6">
        <div class="flex items-start gap-3">
            <x-icon name="document" class="h-6 w-6 text-indigo-600 shrink-0" />
            <div>
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Merge Subjects</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Pick which subject survives &mdash; every other selected subject is merged into it. Enrollments and pinned slots in sessions that aren't finalized move onto the survivor; finalized sessions keep their original historical record untouched.</p>
            </div>
        </div>
        <div class="mt-4 space-y-2">
            @foreach (\App\Models\Subject::whereIn('id', $selected)->orderBy('code')->get() as $subject)
                <label class="flex items-center gap-2 text-sm text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2 cursor-pointer">
                    <input type="radio" wire:model="survivorId" value="{{ $subject->id }}" class="text-indigo-600 focus:ring-indigo-500">
                    <span class="font-medium">{{ $subject->code }}</span>
                    <span class="text-gray-500 dark:text-gray-400">&mdash; {{ $subject->title }}</span>
                </label>
            @endforeach
        </div>
        <div class="mt-6 flex justify-end gap-3">
            <x-btn variant="secondary" wire:click="closeMergeModal" x-on:click="$dispatch('close')">Cancel</x-btn>
            <x-btn wire:click="confirmMerge" wire:confirm="Merge these subjects? This cannot be undone." wire:loading.attr="disabled" variant="dark">
                <span wire:loading.remove wire:target="confirmMerge">Merge</span>
                <span wire:loading wire:target="confirmMerge">Merging&hellip;</span>
            </x-btn>
        </div>
    </div>
</x-modal>

<x-card>
    <div class="flex items-center justify-between mb-2 gap-3">
        <div class="relative w-full max-w-xs">
            <x-icon name="search" class="h-4 w-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" />
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search code or title"
                class="pl-9 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
        </div>
        <x-per-page-selector />
    </div>

    @if (count($selected) > 0)
        <div class="flex items-center gap-3 mb-3 p-3 bg-indigo-50 dark:bg-indigo-900/20 rounded-lg">
            <span class="text-sm text-indigo-700 dark:text-indigo-300">{{ count($selected) }} selected</span>
            <x-btn wire:click="openMergeModal" variant="dark" size="sm" icon="document">Merge Selected</x-btn>
            <button type="button" wire:click="clearSelection" class="text-sm text-gray-500 dark:text-gray-400 hover:underline">Clear selection</button>
        </div>
    @endif

    @if ($subjects->isEmpty())
        <x-empty-state icon="document" title="No subjects yet" description="Subjects are created automatically the first time they appear in an enrollment import." />
    @else
        @php
            $pageIds = $subjects->reject(fn ($s) => $s->isMerged())->pluck('id')->all();
            $allOnPageSelected = ! empty($pageIds) && empty(array_diff($pageIds, $selected));
        @endphp
        <div class="overflow-x-auto -mx-4 sm:-mx-6">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead>
                    <tr class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <th class="py-2.5 pl-4 sm:pl-6 pr-2 w-8">
                            <input type="checkbox" wire:click="toggleSelectAllOnPage({{ Illuminate\Support\Js::from($pageIds) }})" @checked($allOnPageSelected) title="Select all mergeable subjects on this page" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        </th>
                        <th class="py-2.5 pr-4">Code</th>
                        <th class="py-2.5 pr-4">Title</th>
                        <th class="py-2.5 pr-4">Credit Hours</th>
                        <th class="py-2.5 pr-4 sm:pr-6">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($subjects as $subject)
                        <tr @class(['opacity-60' => $subject->isMerged(), 'hover:bg-gray-50 dark:hover:bg-gray-900/30' => ! $subject->isMerged()])>
                            <td class="py-3 pl-4 sm:pl-6 pr-2">
                                @unless ($subject->isMerged())
                                    <input type="checkbox" wire:model.live="selected" value="{{ $subject->id }}" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                @endunless
                            </td>
                            <td class="py-3 pr-4 font-medium text-gray-900 dark:text-gray-100">{{ $subject->code }}</td>
                            <td class="py-3 pr-4 text-gray-500 dark:text-gray-400">{{ $subject->title }}</td>
                            <td class="py-3 pr-4 text-gray-500 dark:text-gray-400">{{ $subject->credit_hours ?? '—' }}</td>
                            <td class="py-3 pr-4 sm:pr-6">
                                @if ($subject->isMerged())
                                    <x-badge color="gray">Merged into {{ $subject->mergedInto->code }}</x-badge>
                                @else
                                    <x-badge color="green">Active</x-badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">
            {{ $subjects->links() }}
        </div>
    @endif
</x-card>
</div>
