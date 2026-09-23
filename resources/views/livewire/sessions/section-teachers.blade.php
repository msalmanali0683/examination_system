<x-slot name="header">
    <x-page-header title="Section Teachers" subtitle="Every subject/section pair's currently assigned teacher — change any of them, whether missing or already set." icon="user-group" :back="route('sessions.generate', $examSession)" />
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

<x-finalized-banner :session="$examSession" />

<x-card :padded="false">
    <div class="p-4 sm:p-6 border-b border-gray-100 dark:border-gray-700">
        <div class="relative w-full max-w-xs">
            <x-icon name="search" class="h-4 w-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" />
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search subject code or title"
                class="pl-9 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
        </div>
    </div>

    @if ($rows->isEmpty())
        <x-empty-state icon="user-group" title="No subjects found" description="No enrolled subject/section pairs match this search." />
    @else
        <div class="p-4 sm:p-6">
            <div class="overflow-x-auto -mx-4 sm:-mx-6">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead>
                        <tr class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                            <th class="py-2 pl-4 sm:pl-6 pr-4">Subject</th>
                            <th class="py-2 pr-4">Section</th>
                            <th class="py-2 pr-4">Students</th>
                            <th class="py-2 pr-4">Current Teacher</th>
                            <th class="py-2 pr-4 sm:pr-6">Change Teacher</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($rows as $row)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/30">
                                <td class="py-2.5 pl-4 sm:pl-6 pr-4 text-gray-900 dark:text-gray-100">{{ $row->code }} &mdash; {{ $row->title }}</td>
                                <td class="py-2.5 pr-4 text-gray-500 dark:text-gray-400">{{ $row->section }}</td>
                                <td class="py-2.5 pr-4 text-gray-500 dark:text-gray-400">{{ $row->student_count }}</td>
                                <td class="py-2.5 pr-4">
                                    @if ($row->teacher === null)
                                        <x-badge color="red">Missing</x-badge>
                                    @elseif ($row->teacher === 'mixed')
                                        <x-badge color="yellow" title="Not every student in this section has the same teacher on record.">Mixed</x-badge>
                                    @else
                                        <span class="text-gray-700 dark:text-gray-300">{{ $row->teacher->name }}</span>
                                    @endif
                                </td>
                                <td class="py-2.5 pr-4 sm:pr-6">
                                    <div class="flex items-center gap-2">
                                        <select wire:model="selection.{{ $row->subject_id }}.{{ $row->section }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                                            <option value="">Select teacher&hellip;</option>
                                            @foreach ($activeTeachers as $teacher)
                                                <option value="{{ $teacher->id }}">{{ $teacher->name }}</option>
                                            @endforeach
                                        </select>
                                        <x-btn wire:click="changeTeacher({{ $row->subject_id }}, {{ Illuminate\Support\Js::from($row->section) }})" wire:loading.attr="disabled" variant="secondary">Change</x-btn>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-card>
</div>
