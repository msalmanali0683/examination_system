<x-slot name="header">
    <x-page-header :title="$examSession->name" icon="calendar" :back="route('sessions.index')">
        <x-slot name="actions">
            <x-btn :href="route('sessions.generate', $examSession)" wire:navigate icon="lightning">Generate Timetable</x-btn>
        </x-slot>
    </x-page-header>
</x-slot>

<div x-data="{ tab: 'rooms' }" class="space-y-6">

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
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div class="flex items-center gap-3">
                <x-badge :color="match($examSession->status) { 'generated' => 'blue', 'finalized' => 'green', default => 'gray' }">
                    {{ ucfirst($examSession->status) }}
                </x-badge>
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $examSession->start_date->format('d M Y') }} &ndash; {{ $examSession->end_date->format('d M Y') }}
                </span>
            </div>
            @if (! $editingDetails)
                <button type="button" wire:click="editDetails" class="text-sm font-medium text-indigo-600 hover:underline">Edit</button>
            @endif
        </div>

        @if ($editingDetails)
            <form wire:submit="saveDetails" class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4 border-t border-gray-100 dark:border-gray-700 pt-4">
                <div class="sm:col-span-3">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Session Name</label>
                    <input type="text" wire:model="name" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                    @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Start Date</label>
                    <input type="date" wire:model="start_date" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                    @error('start_date') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">End Date</label>
                    <input type="date" wire:model="end_date" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                    @error('end_date') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div class="sm:col-span-3 flex items-center gap-3">
                    <x-btn type="submit" icon="check">Save</x-btn>
                    <x-btn type="button" variant="ghost" wire:click="$set('editingDetails', false)">Cancel</x-btn>
                </div>
            </form>
        @endif
    </x-card>

    <x-card :padded="false">
        <div class="border-b border-gray-100 dark:border-gray-700 px-4 sm:px-6 flex gap-1 overflow-x-auto">
            <button type="button" @click="tab = 'rooms'" :class="tab === 'rooms' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'" class="flex items-center gap-1.5 py-3.5 px-2 border-b-2 text-sm font-medium whitespace-nowrap">
                <x-icon name="door" class="h-4 w-4" /> Rooms
            </button>
            <button type="button" @click="tab = 'teachers'" :class="tab === 'teachers' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'" class="flex items-center gap-1.5 py-3.5 px-2 border-b-2 text-sm font-medium whitespace-nowrap">
                <x-icon name="cap" class="h-4 w-4" /> Teacher Constraints
            </button>
            <button type="button" @click="tab = 'slots'" :class="tab === 'slots' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'" class="flex items-center gap-1.5 py-3.5 px-2 border-b-2 text-sm font-medium whitespace-nowrap">
                <x-icon name="calendar" class="h-4 w-4" /> Time Slots
            </button>
            <button type="button" @click="tab = 'enrollments'" :class="tab === 'enrollments' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'" class="flex items-center gap-1.5 py-3.5 px-2 border-b-2 text-sm font-medium whitespace-nowrap">
                <x-icon name="users" class="h-4 w-4" /> Enrollments
            </button>
            @can('view_reports')
                <button type="button" @click="tab = 'reports'" :class="tab === 'reports' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'" class="flex items-center gap-1.5 py-3.5 px-2 border-b-2 text-sm font-medium whitespace-nowrap">
                    <x-icon name="download" class="h-4 w-4" /> Reports
                </button>
            @endcan
        </div>

        <div class="p-4 sm:p-6">
            <div x-show="tab === 'rooms'">
                <livewire:sessions.room-selection :exam-session="$examSession" :key="'rooms-'.$examSession->id" />
            </div>
            <div x-show="tab === 'teachers'">
                <livewire:sessions.teacher-constraints :exam-session="$examSession" :key="'teachers-'.$examSession->id" />
            </div>
            <div x-show="tab === 'slots'">
                <livewire:sessions.time-slots :exam-session="$examSession" :key="'slots-'.$examSession->id" />
            </div>
            <div x-show="tab === 'enrollments'">
                <div class="grid grid-cols-3 gap-4 text-center mb-6">
                    <div class="p-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg">
                        <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $enrollmentCount }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Enrollments</div>
                    </div>
                    <div class="p-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg">
                        <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $studentCount }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Students</div>
                    </div>
                    <div class="p-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg">
                        <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $subjectCount }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Subjects</div>
                    </div>
                </div>
                <div class="flex items-center gap-3 flex-wrap">
                    <x-btn :href="route('sessions.enrollments.import', $examSession)" wire:navigate icon="upload">Import Enrollments</x-btn>
                    @can('manage_enrollments')
                        @if ($enrollmentCount > 0 && ! $examSession->isFinalized())
                            <x-btn wire:click="resetEnrollments" wire:confirm="This deletes all {{ $enrollmentCount }} enrollment(s) (and any generated seating) for this session so you can re-import from scratch. This cannot be undone. Continue?" variant="danger" icon="trash">
                                Reset Enrollments
                            </x-btn>
                        @endif
                    @endcan
                </div>
            </div>
            @can('view_reports')
                <div x-show="tab === 'reports'">
                    <livewire:sessions.report-downloads :exam-session="$examSession" :key="'reports-show-'.$examSession->id" />
                </div>
            @endcan
        </div>
    </x-card>
</div>
