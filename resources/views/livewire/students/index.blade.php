<x-slot name="header">
    <x-page-header title="Students" subtitle="{{ $examSession->name }} — the students in this session, sourced from its enrollment imports." icon="user-group" :back="route('sessions.show', $examSession)">
        <x-slot name="actions">
            @if (! $showForm)
                <x-btn wire:click="addStudent" icon="plus">Add Student</x-btn>
            @endif
        </x-slot>
    </x-page-header>
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

<x-card>
    @if ($showForm)
        <form wire:submit="save" class="grid grid-cols-1 sm:grid-cols-2 gap-4 pb-6 mb-6 border-b border-gray-100 dark:border-gray-700">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Roll No / SAP No</label>
                <input type="text" wire:model="roll_no" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                @error('roll_no') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Name</label>
                <input type="text" wire:model="name" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Program</label>
                <input type="text" wire:model="program" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Admission Year</label>
                <input type="text" wire:model="admission_year" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
            </div>
            <div class="sm:col-span-2 flex items-center gap-3">
                <x-btn type="submit" icon="check">Save Student</x-btn>
                <x-btn type="button" variant="ghost" wire:click="cancel">Cancel</x-btn>
            </div>
        </form>
    @endif

    <div class="flex items-center justify-between mb-2 gap-3">
        <div class="relative w-full max-w-xs">
            <x-icon name="search" class="h-4 w-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" />
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search roll no or name"
                class="pl-9 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
        </div>
        <div class="flex items-center gap-3">
            @if ($students->total() > 0)
                <x-btn wire:click="deleteAllStudents" wire:confirm="Delete ALL {{ $students->total() }} student(s){{ $search ? ' matching \''.$search.'\'' : '' }}? This also removes their enrollments and any seats assigned to them in this session. This cannot be undone." variant="danger" size="sm" icon="trash">
                    Delete All{{ $search ? ' Matching' : '' }}
                </x-btn>
            @endif
            <x-per-page-selector />
        </div>
    </div>

    @if (count($selected) > 0)
        <div class="flex items-center gap-3 mb-3 p-3 bg-red-50 dark:bg-red-900/20 rounded-lg">
            <span class="text-sm text-red-700 dark:text-red-300">{{ count($selected) }} selected</span>
            <x-btn wire:click="bulkDelete" wire:confirm="Delete {{ count($selected) }} student(s)? This also removes their enrollments and any seats assigned to them in this session. This cannot be undone." variant="danger" size="sm" icon="trash">Delete Selected</x-btn>
            <button type="button" wire:click="clearSelection" class="text-sm text-gray-500 dark:text-gray-400 hover:underline">Clear selection</button>
        </div>
    @endif

    @if ($students->isEmpty())
        <x-empty-state icon="user-group" title="No students yet" description="Students are created automatically the first time you import enrollments, or add one manually." />
    @else
        @php
            $pageIds = $students->pluck('id')->all();
            $allOnPageSelected = ! empty($pageIds) && empty(array_diff($pageIds, $selected));
        @endphp
        <div class="overflow-x-auto -mx-4 sm:-mx-6">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead>
                    <tr class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <th class="py-2.5 pl-4 sm:pl-6 pr-2 w-8">
                            <input type="checkbox" wire:click="toggleSelectAllOnPage({{ Illuminate\Support\Js::from($pageIds) }})" @checked($allOnPageSelected) title="Select all on this page" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        </th>
                        <th class="py-2.5 pr-4">Roll No</th>
                        <th class="py-2.5 pr-4">Name</th>
                        <th class="py-2.5 pr-4">Program</th>
                        <th class="py-2.5 pr-4">Admission Year</th>
                        <th class="py-2.5 pr-4 sm:pr-6"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($students as $student)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/30">
                            <td class="py-3 pl-4 sm:pl-6 pr-2">
                                <input type="checkbox" wire:model.live="selected" value="{{ $student->id }}" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            </td>
                            <td class="py-3 pr-4 font-medium text-gray-900 dark:text-gray-100">{{ $student->roll_no }}</td>
                            <td class="py-3 pr-4 text-gray-500 dark:text-gray-400">{{ $student->name }}</td>
                            <td class="py-3 pr-4 text-gray-500 dark:text-gray-400">{{ $student->program }}</td>
                            <td class="py-3 pr-4 text-gray-500 dark:text-gray-400">{{ $student->admission_year }}</td>
                            <td class="py-3 pr-4 sm:pr-6 text-right space-x-3 whitespace-nowrap">
                                <button type="button" wire:click="editStudent({{ $student->id }})" class="text-sm font-medium text-indigo-600 hover:underline">Edit</button>
                                <button type="button" wire:click="deleteStudent({{ $student->id }})" wire:confirm="Delete {{ $student->name }}? This also removes their enrollments and any seats assigned to them in this session." class="text-sm font-medium text-red-600 hover:underline">Delete</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">
            {{ $students->links() }}
        </div>
    @endif
</x-card>
</div>
