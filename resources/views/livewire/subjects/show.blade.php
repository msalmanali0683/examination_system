<x-slot name="header">
    <x-page-header title="{{ $subject->code }} — {{ $subject->title }}" subtitle="{{ $examSession->name }} — every section this subject is enrolled under, with a way to merge labels that are really the same class." icon="document" :back="route('sessions.subjects.index', $examSession)" />
</x-slot>

<div class="space-y-6">
<x-finalized-banner :session="$examSession" />

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

<x-modal name="merge-sections" :show="$showMergeModal" focusable max-width="lg">
    <div class="p-6">
        <div class="flex items-start gap-3">
            <x-icon name="document" class="h-6 w-6 text-primary-600 shrink-0" />
            <div>
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Merge Sections</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Pick which section label survives &mdash; every other selected section's students are relabeled onto it.</p>
            </div>
        </div>
        <div class="mt-4 space-y-2">
            @foreach ($selected as $section)
                <label class="flex items-center gap-2 text-sm text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2 cursor-pointer">
                    <input type="radio" wire:model="keepSection" value="{{ $section }}" class="text-primary-600 focus:ring-primary-500">
                    <span class="font-medium">{{ $section }}</span>
                </label>
            @endforeach
        </div>
        <div class="mt-6 flex justify-end gap-3">
            <x-btn variant="secondary" wire:click="closeMergeModal" x-on:click="$dispatch('close')">Cancel</x-btn>
            <x-btn wire:click="confirmMerge" wire:confirm="Merge these sections? This cannot be undone." wire:loading.attr="disabled" variant="dark">
                <span wire:loading.remove wire:target="confirmMerge">Merge</span>
                <span wire:loading wire:target="confirmMerge">Merging&hellip;</span>
            </x-btn>
        </div>
    </div>
</x-modal>

<x-card>
    <div class="flex items-center justify-between mb-2 gap-3">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $sections->count() }} section(s), {{ $sections->sum('student_count') }} student(s) total.</p>
    </div>

    @if (count($selected) > 0)
        <div class="flex items-center gap-3 mb-3 p-3 bg-primary-50 dark:bg-primary-900/20 rounded-lg">
            <span class="text-sm text-primary-700 dark:text-primary-300">{{ count($selected) }} selected</span>
            <x-btn wire:click="openMergeModal" variant="dark" size="sm" icon="document">Merge Selected</x-btn>
            <button type="button" wire:click="clearSelection" class="text-sm text-gray-500 dark:text-gray-400 hover:underline">Clear selection</button>
        </div>
    @endif

    @if ($sections->isEmpty())
        <x-empty-state icon="document" title="No enrollments yet" description="This subject has no enrolled students in this session yet." />
    @else
        <div class="overflow-x-auto -mx-4 sm:-mx-6">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead>
                    <tr class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <th class="py-2.5 pl-4 sm:pl-6 pr-2 w-8"><span class="sr-only">Select</span></th>
                        <th class="py-2.5 pr-4">Section</th>
                        <th class="py-2.5 pr-4 sm:pr-6">Students</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($sections as $row)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/30">
                            <td class="py-3 pl-4 sm:pl-6 pr-2">
                                <input type="checkbox" wire:model.live="selected" value="{{ $row->section }}" aria-label="Select {{ $row->section }}" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                            </td>
                            <td class="py-3 pr-4 font-medium text-gray-900 dark:text-gray-100">{{ $row->section }}</td>
                            <td class="py-3 pr-4 sm:pr-6 text-gray-500 dark:text-gray-400">{{ $row->student_count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-card>
</div>
